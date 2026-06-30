const assert = require('node:assert/strict');

const MICRO = 1_000_000n;
const TENTH = 100_000n;
const CENT = 10_000n;
const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
const RECEIVER = 'T_RECEIVER';

function decimalToMicro(value, ceilExtra = false) {
  const text = String(value).replace(/,/g, '').trim();
  const match = text.match(/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/);
  if (!match) throw new Error('invalid_decimal_amount');
  const whole = BigInt(match[1]);
  const fraction = match[2] || '';
  const firstSix = fraction.slice(0, 6).padEnd(6, '0');
  const extra = fraction.slice(6);
  if (!ceilExtra && /[1-9]/.test(extra)) throw new Error('amount_precision_too_high');
  let micro = whole * MICRO + BigInt(firstSix || '0');
  if (ceilExtra && /[1-9]/.test(extra)) micro += 1n;
  return micro;
}

function microToDecimal(micro, scale = 2) {
  micro = BigInt(micro);
  const sign = micro < 0n ? '-' : '';
  if (micro < 0n) micro = -micro;
  const whole = micro / MICRO;
  const fraction = String(micro % MICRO).padStart(6, '0').slice(0, scale);
  return `${sign}${whole}${scale > 0 ? `.${fraction}` : ''}`;
}

function ceilDiv(numerator, denominator) {
  if (denominator <= 0n) throw new Error('invalid_denominator');
  if (numerator <= 0n) return 0n;
  return (numerator + denominator - 1n) / denominator;
}

function convertToUsdMicro(amount, sourceRate, usdRate) {
  const amountMicro = decimalToMicro(amount, true);
  const sourceRateMicro = decimalToMicro(sourceRate, true);
  const usdRateMicro = decimalToMicro(usdRate, true);
  if (amountMicro <= 0n) throw new Error('invoice_amount_invalid');
  if (sourceRateMicro <= 0n) throw new Error('source_currency_rate_invalid');
  if (usdRateMicro <= 0n) throw new Error('usd_currency_rate_invalid');
  return ceilDiv(amountMicro * usdRateMicro, sourceRateMicro);
}

function ceilToStep(micro, step) {
  return ceilDiv(BigInt(micro), BigInt(step)) * BigInt(step);
}

function detectGatewayConversion(paramsCurrency, invoiceCurrency) {
  if (String(paramsCurrency).toUpperCase() !== String(invoiceCurrency).toUpperCase()) {
    throw new Error('gateway_convert_to_detected');
  }
}

function transferValue(row, keys) {
  for (const key of keys) {
    const parts = key.split('.');
    let value = row;
    let found = true;
    for (const part of parts) {
      if (value && Object.prototype.hasOwnProperty.call(value, part)) {
        value = value[part];
      } else {
        found = false;
        break;
      }
    }
    if (found && value !== undefined && value !== null && value !== '') return value;
  }
  return null;
}

function normalizeTronScanTransfer(row) {
  const rawAmount = transferValue(row, ['raw_amount', 'amount', 'quant', 'value']);
  if (!/^[0-9]+$/.test(String(rawAmount))) throw new Error('transfer_amount_missing');

  return {
    txid: String(transferValue(row, ['transaction_id', 'hash', 'txid', 'transactionHash'])).toLowerCase(),
    from: String(transferValue(row, ['from_address', 'from', 'transferFromAddress'])),
    to: String(transferValue(row, ['to_address', 'to', 'transferToAddress'])),
    contract: String(transferValue(row, ['id', 'trc20Id', 'contract_address', 'contract', 'tokenInfo.tokenId', 'tokenInfo.address'])),
    eventType: String(transferValue(row, ['event_type', 'eventType', 'event_name'])),
    contractRet: String(transferValue(row, ['contract_ret', 'contractRet', 'contract_result'])),
    revert: transferValue(row, ['revert', 'reverted']),
    amountMicro: BigInt(rawAmount),
    block: Number(transferValue(row, ['block', 'block_number', 'blockNumber'])),
    blockTimestamp: Number(transferValue(row, ['block_timestamp', 'block_ts', 'timestamp', 'time'])),
    direction: Number(transferValue(row, ['direction'])),
  };
}

function buildTransferQuery(startTimestamp, endTimestamp, start = 0, limit = 50) {
  return {
    address: RECEIVER,
    trc20Id: USDT_CONTRACT,
    direction: 2,
    reverse: 'true',
    db_version: 1,
    start,
    limit,
    start_timestamp: startTimestamp,
    end_timestamp: endTimestamp,
  };
}

class GatewayModel {
  constructor() {
    this.now = 1_000_000;
    this.currencies = {
      HKD: { code: 'HKD', rate: '7.800000' },
      USD: { code: 'USD', rate: '1.000000' },
    };
    this.invoices = new Map();
    this.intents = [];
    this.transactions = new Map();
    this.credits = [];
    this.scanQueries = [];
  }

  invoice(id, amount, currency = 'HKD', status = 'Unpaid') {
    this.invoices.set(id, { id, amount, balance: amount, currency, status });
  }

  expireStale() {
    for (const intent of this.intents) {
      if (intent.status === 'pending' && !intent.txid && intent.expiresAt < this.now) {
        intent.status = 'expired';
        intent.activeAmountKey = null;
      }
    }
  }

  createIntent(invoiceId) {
    this.expireStale();
    const invoice = this.invoices.get(invoiceId);
    if (!invoice || invoice.status !== 'Unpaid') throw new Error('invoice_not_payable');
    const source = this.currencies[invoice.currency];
    const usd = this.currencies.USD;
    if (!source) throw new Error('invoice_currency_missing');
    if (!usd) throw new Error('usd_currency_missing');
    if (decimalToMicro(source.rate, true) <= 0n) throw new Error('source_currency_rate_invalid');
    if (decimalToMicro(usd.rate, true) <= 0n) throw new Error('usd_currency_rate_invalid');

    const computed = convertToUsdMicro(invoice.balance, source.rate, usd.rate);
    const base = ceilToStep(computed, TENTH);

    for (let slot = 1; slot <= 9; slot += 1) {
      const expected = base + BigInt(slot) * CENT;
      if (this.intents.some((intent) => intent.activeAmountKey === String(expected))) continue;
      const intent = {
        id: this.intents.length + 1,
        invoiceId,
        invoiceCurrency: invoice.currency,
        invoiceBalanceSnapshot: invoice.balance,
        expectedMicro: expected,
        expectedAmount: microToDecimal(expected, 6),
        base,
        computed,
        slot,
        status: 'pending',
        txid: null,
        activeAmountKey: String(expected),
        expiresAt: this.now + 30 * 60,
      };
      this.intents.push(intent);
      return intent;
    }

    return null;
  }

  processTransfer(rawRow, latestBlock) {
    const row = normalizeTronScanTransfer(rawRow);
    const tx = this.transactions.get(row.txid);
    if (tx && tx.status === 'processed') return 'duplicate_processed';
    if (tx && tx.status === 'processing') return 'duplicate_processing';
    const terminal = new Set([
      'wrong_address',
      'wrong_contract',
      'invalid_transfer_event',
      'failed_contract_result',
      'reverted_transfer',
      'unmatched',
      'invoice_not_payable',
      'invoice_amount_changed',
      'request_already_claimed',
    ]);
    if (tx && terminal.has(tx.status)) return tx.status;

    if (row.to !== RECEIVER) return this.store(row.txid, 'wrong_address');
    if (row.contract !== USDT_CONTRACT) return this.store(row.txid, 'wrong_contract');
    if (row.eventType !== 'Transfer') return this.store(row.txid, 'invalid_transfer_event');
    if (row.contractRet !== 'SUCCESS') return this.store(row.txid, 'failed_contract_result');
    if (!(row.revert === 0 || row.revert === '0' || row.revert === false)) return this.store(row.txid, 'reverted_transfer');

    const confirmations = latestBlock && row.block ? latestBlock - row.block : null;
    if (confirmations === null || confirmations < 0) return this.store(row.txid, 'confirmations_unavailable');
    if (confirmations < 12) return this.store(row.txid, 'waiting_confirmations');

    this.expireStale();
    const matches = this.intents.filter((intent) => intent.status === 'pending' && !intent.txid && intent.expectedMicro === row.amountMicro && intent.expiresAt >= this.now);
    if (matches.length !== 1) return this.store(row.txid, matches.length === 0 ? 'unmatched' : 'conflict');

    const intent = matches[0];
    const invoice = this.invoices.get(intent.invoiceId);
    if (!invoice || invoice.status !== 'Unpaid') return this.store(row.txid, 'invoice_not_payable');
    if (invoice.currency !== intent.invoiceCurrency || invoice.balance !== intent.invoiceBalanceSnapshot) {
      return this.store(row.txid, 'invoice_amount_changed');
    }

    if (this.transactions.has(row.txid)) return this.transactions.get(row.txid).status;
    this.transactions.set(row.txid, { status: 'processing' });
    intent.txid = row.txid;
    intent.activeAmountKey = null;

    this.credits.push({ invoiceId: intent.invoiceId, amount: intent.invoiceBalanceSnapshot, txid: row.txid });
    intent.status = 'paid';
    invoice.status = 'Paid';
    this.transactions.set(row.txid, { status: 'processed' });
    return 'paid';
  }

  scanTronScan(response, latestBlock) {
    const rows = response.token_transfers || response.data || [];
    return rows.map((row) => this.processTransfer(row, latestBlock));
  }

  scanTronScanPages(fetchPage, latestBlock, startTimestamp, endTimestamp) {
    const statuses = [];
    const limit = 50;
    for (let start = 0; ; start += limit) {
      const query = buildTransferQuery(startTimestamp, endTimestamp, start, limit);
      this.scanQueries.push(query);
      const response = fetchPage(query);
      const rows = response.token_transfers || response.data || [];
      for (const row of rows) statuses.push(this.processTransfer(row, latestBlock));
      if (rows.length < limit) break;
    }
    return statuses;
  }

  store(txid, status) {
    const existing = this.transactions.get(txid);
    if (!existing || ['received', 'waiting_confirmations', 'confirmations_unavailable'].includes(existing.status)) {
      this.transactions.set(txid, { status });
    }
    return this.transactions.get(txid).status;
  }
}

function transfer(overrides = {}) {
  return {
    hash: overrides.hash || overrides.txid || 'a'.repeat(64),
    from: overrides.from || 'T_SENDER',
    to: overrides.to || RECEIVER,
    amount: overrides.amount || overrides.quant || '12910000',
    block: overrides.block ?? 88,
    block_timestamp: overrides.block_timestamp ?? overrides.block_ts ?? 1700000000000,
    revert: overrides.revert ?? 0,
    event_type: overrides.event_type || 'Transfer',
    contract_ret: overrides.contract_ret || 'SUCCESS',
    id: overrides.id || overrides.trc20Id || USDT_CONTRACT,
    direction: overrides.direction ?? 2,
  };
}

{
  const usd = convertToUsdMicro('100.00', '7.800000', '1.000000');
  assert.equal(microToDecimal(usd, 6), '12.820513');
  assert.equal(microToDecimal(ceilToStep(usd, TENTH), 2), '12.90');
  assert.throws(() => detectGatewayConversion('USD', 'HKD'), /gateway_convert_to_detected/);
}

{
  const sample = transfer();
  assert.deepEqual(Object.keys(sample), [
    'hash',
    'from',
    'to',
    'amount',
    'block',
    'block_timestamp',
    'revert',
    'event_type',
    'contract_ret',
    'id',
    'direction',
  ]);
  const normalized = normalizeTronScanTransfer(sample);
  assert.equal(normalized.amountMicro, 12_910_000n);
  assert.equal(microToDecimal(normalized.amountMicro, 6), '12.910000');
  assert.equal(normalized.contract, USDT_CONTRACT);
  assert.equal(normalized.direction, 2);

  const legacyContract = transfer({ txid: 'f'.repeat(64) });
  delete legacyContract.id;
  legacyContract.contract_address = USDT_CONTRACT;
  assert.equal(normalizeTronScanTransfer(legacyContract).contract, USDT_CONTRACT);

  assert.equal(buildTransferQuery(1000, 2000).direction, 2);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  delete model.currencies.USD;
  assert.throws(() => model.createIntent(1), /usd_currency_missing/);
  model.currencies.USD = { code: 'USD', rate: '0' };
  assert.throws(() => model.createIntent(1), /usd_currency_rate_invalid/);
}

{
  const model = new GatewayModel();
  for (let invoiceId = 1; invoiceId <= 9; invoiceId += 1) {
    model.invoice(invoiceId, '100.00');
    const intent = model.createIntent(invoiceId);
    assert.equal(microToDecimal(intent.expectedMicro, 2), `12.9${invoiceId}`);
  }
  model.invoice(10, '100.00');
  assert.equal(model.createIntent(10), null);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  assert.equal(microToDecimal(model.createIntent(1).expectedMicro, 2), '12.91');
  model.now += 31 * 60;
  model.invoice(2, '100.00');
  assert.equal(microToDecimal(model.createIntent(2).expectedMicro, 2), '12.91');
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  assert.equal(model.processTransfer(transfer({ to: 'T_WRONG' }), 100), 'wrong_address');
  assert.equal(model.processTransfer(transfer({ txid: 'b'.repeat(64), trc20Id: 'T_FAKE' }), 100), 'wrong_contract');
  assert.equal(model.processTransfer(transfer({ txid: 'c'.repeat(64), block: 95 }), 100), 'waiting_confirmations');
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  assert.equal(model.scanTronScan({ token_transfers: [transfer()] }, 100)[0], 'paid');
  assert.equal(model.processTransfer(transfer(), 100), 'duplicate_processed');
  assert.equal(model.credits.length, 1);
  assert.deepEqual(model.credits[0], { invoiceId: 1, amount: '100.00', txid: 'a'.repeat(64) });
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  const firstPage = Array.from({ length: 50 }, (_, index) => transfer({
    txid: (index + 1).toString(16).padStart(64, '0'),
    amount: '1',
  }));
  const secondPage = [transfer()];
  const statuses = model.scanTronScanPages((query) => {
    if (query.start === 0) return { token_transfers: firstPage };
    if (query.start === 50) return { token_transfers: secondPage };
    return { token_transfers: [] };
  }, 100, 1000, 2000);
  assert.equal(statuses.at(-1), 'paid');
  assert.deepEqual(model.scanQueries.map((query) => query.start), [0, 50]);
  assert.ok(model.scanQueries.every((query) => query.direction === 2));
  assert.equal(model.credits.length, 1);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  model.invoices.get(1).status = 'Paid';
  assert.equal(model.processTransfer(transfer(), 100), 'invoice_not_payable');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  model.invoices.get(1).status = 'Cancelled';
  assert.equal(model.processTransfer(transfer(), 100), 'invoice_not_payable');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  model.invoices.get(1).balance = '90.00';
  assert.equal(model.processTransfer(transfer(), 100), 'invoice_amount_changed');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  model.now += 31 * 60;
  assert.equal(model.processTransfer(transfer(), 100), 'unmatched');
  assert.equal(model.intents[0].status, 'expired');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createIntent(1);
  assert.equal(model.processTransfer(transfer({ txid: 'd'.repeat(64) }), 100), 'paid');
  assert.equal(model.processTransfer(transfer({ txid: 'e'.repeat(64) }), 100), 'unmatched');
  assert.equal(model.credits.length, 1);
}

console.log('OWP-PGW-U scenario tests passed');
