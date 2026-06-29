const assert = require('node:assert/strict');

const MICRO = 1_000_000n;
const TENTH = 100_000n;
const CENT = 10_000n;

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

class GatewayModel {
  constructor() {
    this.now = 1_000_000;
    this.currencies = {
      HKD: { code: 'HKD', rate: '7.800000' },
      USD: { code: 'USD', rate: '1.000000' },
    };
    this.invoices = new Map();
    this.requests = [];
    this.transactions = new Map();
    this.credits = [];
  }

  invoice(id, amount, currency = 'HKD', status = 'Unpaid') {
    this.invoices.set(id, { id, amount, balance: amount, currency, status });
  }

  expireStale() {
    for (const request of this.requests) {
      if (request.status === 'pending' && request.expiresAt < this.now) {
        request.status = 'expired';
        request.activeAmountKey = null;
      }
    }
  }

  createRequest(invoiceId) {
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
      const display = base + BigInt(slot) * CENT;
      if (this.requests.some((request) => request.activeAmountKey === String(display))) continue;
      const request = {
        id: this.requests.length + 1,
        invoiceId,
        invoiceCurrency: invoice.currency,
        invoiceAmount: invoice.balance,
        sourceRate: source.rate,
        usdRate: usd.rate,
        computed,
        base,
        display,
        slot,
        status: 'pending',
        txid: null,
        activeAmountKey: String(display),
        expiresAt: this.now + 30 * 60,
      };
      this.requests.push(request);
      return request;
    }

    return null;
  }

  callback(payload) {
    const tx = this.transactions.get(payload.txid);
    if (tx && tx.status === 'processed') return 'duplicate_processed';
    if (tx && tx.status === 'processing') return 'duplicate_processing';
    const terminal = new Set([
      'wrong_address',
      'wrong_contract',
      'unmatched',
      'conflict',
      'invoice_not_payable',
      'invoice_amount_changed',
      'request_already_claimed',
    ]);
    if (tx && terminal.has(tx.status)) return tx.status;

    if (payload.to !== 'T_RECEIVER') return this.store(payload.txid, 'wrong_address');
    if (payload.contract !== 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t') return this.store(payload.txid, 'wrong_contract');
    if (payload.confirmations < 12) return this.store(payload.txid, 'waiting_confirmations');

    const amount = decimalToMicro(payload.amount);
    this.expireStale();
    const matches = this.requests.filter((request) => request.status === 'pending' && request.display === amount && request.expiresAt >= this.now);
    if (matches.length !== 1) return this.store(payload.txid, matches.length === 0 ? 'unmatched' : 'conflict');

    const request = matches[0];
    const invoice = this.invoices.get(request.invoiceId);
    if (!invoice || invoice.status !== 'Unpaid') return this.store(payload.txid, 'invoice_not_payable');
    if (invoice.currency !== request.invoiceCurrency || invoice.balance !== request.invoiceAmount) {
      return this.store(payload.txid, 'invoice_amount_changed');
    }

    if (request.status !== 'pending' || request.txid) return this.store(payload.txid, 'request_already_claimed');
    request.status = 'processing';
    request.txid = payload.txid;
    request.activeAmountKey = null;
    this.transactions.set(payload.txid, { status: 'processing' });

    this.credits.push({ invoiceId: request.invoiceId, amount: request.invoiceAmount, txid: payload.txid });
    request.status = 'paid';
    this.transactions.set(payload.txid, { status: 'processed' });
    invoice.status = 'Paid';
    return 'paid';
  }

  store(txid, status) {
    const existing = this.transactions.get(txid);
    if (!existing || ['received', 'waiting_confirmations'].includes(existing.status)) {
      this.transactions.set(txid, { status });
    }
    return this.transactions.get(txid).status;
  }
}

function validPayload(overrides = {}) {
  return {
    txid: overrides.txid || 'a'.repeat(64),
    to: overrides.to || 'T_RECEIVER',
    contract: overrides.contract || 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
    amount: overrides.amount || '12.91',
    confirmations: overrides.confirmations ?? 12,
  };
}

{
  const usd = convertToUsdMicro('100.00', '7.800000', '1.000000');
  assert.equal(microToDecimal(usd, 6), '12.820513');
  assert.equal(microToDecimal(ceilToStep(usd, TENTH), 2), '12.90');
  assert.throws(() => detectGatewayConversion('USD', 'HKD'), /gateway_convert_to_detected/);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  delete model.currencies.USD;
  assert.throws(() => model.createRequest(1), /usd_currency_missing/);
  model.currencies.USD = { code: 'USD', rate: '0' };
  assert.throws(() => model.createRequest(1), /usd_currency_rate_invalid/);
}

{
  const model = new GatewayModel();
  for (let invoiceId = 1; invoiceId <= 9; invoiceId += 1) {
    model.invoice(invoiceId, '100.00');
    const request = model.createRequest(invoiceId);
    assert.equal(microToDecimal(request.display, 2), `12.9${invoiceId}`);
  }
  model.invoice(10, '100.00');
  assert.equal(model.createRequest(10), null);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  assert.equal(microToDecimal(model.createRequest(1).display, 2), '12.91');
  model.now += 31 * 60;
  model.invoice(2, '100.00');
  assert.equal(microToDecimal(model.createRequest(2).display, 2), '12.91');
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createRequest(1);
  assert.equal(model.callback(validPayload({ to: 'T_WRONG' })), 'wrong_address');
  assert.equal(model.callback(validPayload({ txid: 'b'.repeat(64), contract: 'T_FAKE' })), 'wrong_contract');
  assert.equal(model.callback(validPayload({ txid: 'c'.repeat(64), confirmations: 1 })), 'waiting_confirmations');
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createRequest(1);
  assert.equal(model.callback(validPayload()), 'paid');
  assert.equal(model.callback(validPayload()), 'duplicate_processed');
  assert.equal(model.credits.length, 1);
  assert.deepEqual(model.credits[0], { invoiceId: 1, amount: '100.00', txid: 'a'.repeat(64) });
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createRequest(1);
  model.invoices.get(1).status = 'Paid';
  assert.equal(model.callback(validPayload()), 'invoice_not_payable');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createRequest(1);
  model.invoices.get(1).status = 'Cancelled';
  assert.equal(model.callback(validPayload()), 'invoice_not_payable');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createRequest(1);
  model.invoices.get(1).balance = '90.00';
  assert.equal(model.callback(validPayload()), 'invoice_amount_changed');
  assert.equal(model.credits.length, 0);
}

{
  const model = new GatewayModel();
  model.invoice(1, '100.00');
  model.createRequest(1);
  assert.equal(model.callback(validPayload({ txid: 'd'.repeat(64) })), 'paid');
  assert.equal(model.callback(validPayload({ txid: 'e'.repeat(64) })), 'unmatched');
  assert.equal(model.credits.length, 1);
}

console.log('OWP-PGW-U scenario tests passed');
