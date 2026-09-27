"use client";

import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import PrintApprovalBoxes from "@/components/print/PrintApprovalBoxes";
import PrintWatermark from "@/components/print/PrintWatermark";
import PrintFooter from "@/components/reports/PrintFooter";
import type { CSSProperties } from "react";
import { localizedPrintText, localeDirection, printVisible, resolvePrintLocale, resolvePrintOptions } from "@/lib/print-branding";

type InvoiceKind = "sale" | "purchase";
type DataRecord = Record<string, unknown>;

const objectValue = (value: unknown): DataRecord => {
  if (!value) return {};
  if (typeof value === "object") return value as DataRecord;
  try { return JSON.parse(String(value)) as DataRecord; } catch { return {}; }
};
const text = (...values: unknown[]) => String(values.find((value) => value !== null && value !== undefined && String(value).trim() !== "") ?? "");
const number = (...values: unknown[]) => {
  const value = values.find((item) => item !== null && item !== undefined && item !== "" && Number.isFinite(Number(item)));
  return value === undefined ? 0 : Number(value);
};
const money = (value: unknown) => number(value).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 3 });
const quantity = (line: DataRecord) => {
  if (line.item_type_snapshot === "SERVICE") return number(line.quantity, line.qty);
  const kilograms = number(line.qty_kg);
  if (kilograms > 0) return kilograms;
  return number(line.quantity, line.qty);
};
const linePrice = (line: DataRecord) => {
  if (line.price_unit === "KG") return number(line.unit_price_per_kg, line.unit_price);
  if (line.price_unit === "TON") return number(line.unit_price, number(line.unit_price_per_kg) * 1000);
  return number(line.unit_price, line.unit_price_per_kg);
};
const addressText = (party: DataRecord) => {
  const address = objectValue(party.address);
  return [address.short_address, address.address_line1, address.street_name, address.district, address.city, address.postal_code]
    .filter((value, index, all) => value && all.indexOf(value) === index).join("، ");
};

function PartyBox({ titleAr, titleEn, party }: { titleAr: string; titleEn: string; party: DataRecord }) {
  const rows = [
    ["الاسم", text(party.company_name, party.name)],
    ["الاسم التجاري", text(party.trade_name)],
    ["السجل التجاري", text(party.registration_number, party.commercial_register)],
    ["الرقم الضريبي", text(party.tax_number)],
    ["الهاتف", text(party.phone)],
    ["البريد", text(party.email)],
    ["العنوان", addressText(party)],
  ].filter(([, value]) => value);
  return <section className="tax-party-box">
    <h2><span>{titleAr}</span><small>{titleEn}</small></h2>
    <dl>{rows.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
  </section>;
}

function invoiceTitle(documentType: unknown) {
  const type = String(documentType || "INVOICE").toUpperCase();
  if (type === "TAX_INVOICE") return ["فاتورة ضريبية", "TAX INVOICE"];
  if (type === "SIMPLIFIED_TAX_INVOICE") return ["فاتورة ضريبية مبسطة", "SIMPLIFIED TAX INVOICE"];
  if (type === "CREDIT_NOTE") return ["إشعار دائن", "CREDIT NOTE"];
  if (type === "DEBIT_NOTE") return ["إشعار مدين", "DEBIT NOTE"];
  return ["فاتورة", "INVOICE"];
}

export default function TaxInvoicePrint({ data, profile }: { data: unknown; kind: InvoiceKind; profile?: DataRecord }) {
  const payload = objectValue(data);
  const invoice = objectValue(payload.invoice);
  const lines = Array.isArray(payload.lines) ? payload.lines.map(objectValue) : [];
  const seller = objectValue(invoice.seller_snapshot_json);
  const buyer = objectValue(invoice.buyer_snapshot_json);
  const options = resolvePrintOptions(profile?.print_options, "invoice");
  const locale = resolvePrintLocale(text(profile?.print_locale, profile?.default_language));
  const fieldVisible = (field: string) => options.company_fields?.[field] !== false;
  const companyName = text(profile?.print_company_name, profile?.company_name, seller.company_name, buyer.company_name, "صلب ERP");
  const companyNameEn = text(profile?.company_name_en, profile?.print_company_name_en, seller.company_name_en, buyer.company_name_en);
  const [titleAr, titleEn] = invoiceTitle(invoice.document_type);
  const margin = Math.max(0, Math.min(35, Number(options.margin_mm) || 10));
  const orientation = options.orientation === "landscape" ? "landscape" : "portrait";
  const logoWidth = Math.max(10, Math.min(60, Number(options.logo_width_mm) || 26));
  const showApprovals = printVisible(options, "signature") || printVisible(options, "stamp");
  const amountInWords = text(invoice.amount_in_words, invoice.total_in_words, invoice.grand_total_in_words);
  const headerNote = localizedPrintText(profile?.print_header_texts, locale);

  const scopeKey = typeof profile?.company_id === "string" || typeof profile?.company_id === "number" ? profile.company_id : null;
  return <article dir={localeDirection(locale)} className="tax-invoice-document" style={{ "--print-primary": text(profile?.primary_color, "#0b2a4a") } as CSSProperties}>
    <style jsx global>{`@media print { @page { size: A4 ${orientation}; margin: ${margin}mm; } }`}</style>
    <PrintWatermark profile={profile} family="invoice" />
    <header className="tax-invoice-branding">
      {printVisible(options, "header_image") && options.header_mode !== "TEXT" && <BrandingAssetImage asset="header_image" scopeKey={scopeKey} enabled={Boolean(profile?.header_image_url || profile?.has_header_image || profile?.header_image_path)} alt="ترويسة الشركة" className="tax-invoice-header-image" style={{ maxHeight: `${Math.max(10, Math.min(42, Number(options.header_height_mm) || 24))}mm`, objectFit: "contain" }} />}
      {options.header_mode !== "FULL_IMAGE" && <div className="tax-invoice-brand-row">
        <div className="tax-invoice-company">
          {printVisible(options, "logo") && options.header_mode !== "TEXT" && <BrandingAssetImage asset="logo" scopeKey={scopeKey} enabled={Boolean(profile?.logo_url || profile?.logo_data_uri || profile?.has_logo || profile?.logo_path)} alt="شعار الشركة" className="tax-invoice-logo" style={{ width: `${logoWidth}mm` }} />}
          <div>
            {printVisible(options, "company_name") && <><strong>{companyName}</strong>{companyNameEn && <span lang="en" dir="ltr">{companyNameEn}</span>}</>}
            {printVisible(options, "company_details") && <small>{[
              fieldVisible("phone") ? text(profile?.phone, profile?.print_phone) : "",
              fieldVisible("email") ? text(profile?.email, profile?.print_email) : "",
              fieldVisible("city") ? text(profile?.city, profile?.print_city) : "",
            ].filter(Boolean).join(" • ")}</small>}
          </div>
        </div>
        <div className="tax-invoice-title"><h1>{titleAr}</h1><b lang="en" dir="ltr">{titleEn}</b></div>
        <div className="tax-invoice-legal">
          {printVisible(options, "commercial_register") && fieldVisible("commercial_register") && text(profile?.commercial_register) && <span>س.ت: {text(profile?.commercial_register)}</span>}
          {printVisible(options, "tax_number") && fieldVisible("tax_number") && text(profile?.tax_number) && <span>الرقم الضريبي: {text(profile?.tax_number)}</span>}
        </div>
      </div>}
      {headerNote && <div className="tax-invoice-header-note">{headerNote}</div>}
    </header>

    <section className="tax-invoice-meta">
      <div><span>رقم الفاتورة</span><b dir="ltr">{text(invoice.invoice_number, invoice.document_number, invoice.id)}</b></div>
      <div><span>التاريخ</span><b dir="ltr">{text(invoice.invoice_date, invoice.document_date)}</b></div>
      <div><span>الحالة</span><b>{text(invoice.document_status, invoice.status)}</b></div>
      <div><span>العملة</span><b dir="ltr">{text(invoice.currency_code, "SAR")}</b></div>
      {text(invoice.payment_method, invoice.payment_type) && <div><span>طريقة الدفع</span><b>{text(invoice.payment_method, invoice.payment_type)}</b></div>}
      {printVisible(options, "branch") && text(invoice.branch_name, profile?.branch_name) && <div><span>الفرع</span><b>{text(invoice.branch_name, profile?.branch_name)}</b></div>}
      {number(invoice.exchange_rate) !== 0 && number(invoice.exchange_rate) !== 1 && <div><span>سعر الصرف</span><b dir="ltr">{number(invoice.exchange_rate)}</b></div>}
    </section>

    <section className="tax-invoice-parties">
      <PartyBox titleAr="البائع" titleEn="SELLER" party={seller} />
      <PartyBox titleAr="المشتري" titleEn="BUYER" party={buyer} />
    </section>

    <div className="tax-invoice-lines-wrap">
      <table className="tax-invoice-lines">
        <thead><tr><th>#</th><th>رمز الصنف<br/><small>Item Code</small></th><th>البيان<br/><small>Description</small></th><th>الوحدة<br/><small>Unit</small></th><th>الكمية<br/><small>Qty</small></th><th>سعر الوحدة<br/><small>Unit Price</small></th><th>الخصم<br/><small>Discount</small></th><th>الوعاء الضريبي<br/><small>Taxable</small></th><th>الضريبة %<br/><small>VAT</small></th><th>مبلغ الضريبة<br/><small>VAT Amount</small></th><th>الإجمالي<br/><small>Total</small></th></tr></thead>
        <tbody>{lines.map((line: DataRecord, index: number) => <tr key={text(line.id, index)}>
          <td>{index + 1}</td><td dir="ltr">{text(line.item_code, line.code, "—")}</td><td className="tax-invoice-description">{text(line.item_name, line.description, line.notes, "—")}</td><td>{text(line.unit_code, line.price_unit, "—")}</td><td>{quantity(line).toLocaleString("en-US", { maximumFractionDigits: 3 })}</td><td>{money(linePrice(line))}</td><td>{money(line.discount_amount)}</td><td>{money(line.total_before_vat ?? line.subtotal)}</td><td>{money(line.tax_rate_snapshot ?? line.vat_percent ?? line.vat_rate ?? line.tax_rate)}%</td><td>{money(line.vat_amount ?? line.tax_amount)}</td><td>{money(line.total_after_vat ?? line.line_total ?? line.total_amount)}</td>
        </tr>)}</tbody>
      </table>
    </div>

    <section className="tax-invoice-summary">
      <div className="tax-invoice-notes">
        {text(invoice.notes) && <><b>ملاحظات</b><p>{text(invoice.notes)}</p></>}
        {amountInWords && <><b>المبلغ كتابةً</b><p>{amountInWords}</p></>}
      </div>
      <dl className="tax-invoice-totals">
        <div><dt>الإجمالي قبل الضريبة</dt><dd>{money(invoice.subtotal ?? invoice.total_before_vat)}</dd></div>
        {number(invoice.discount_amount) !== 0 && <div><dt>الخصومات</dt><dd>{money(invoice.discount_amount)}</dd></div>}
        <div><dt>الوعاء الضريبي</dt><dd>{money(invoice.total_before_vat ?? invoice.subtotal)}</dd></div>
        <div><dt>ضريبة القيمة المضافة</dt><dd>{money(invoice.vat_amount ?? invoice.tax_amount)}</dd></div>
        <div className="grand"><dt>الإجمالي النهائي <small>{text(invoice.currency_code, "SAR")}</small></dt><dd>{money(invoice.total_after_vat ?? invoice.grand_total ?? invoice.total_amount)}</dd></div>
      </dl>
    </section>

    {showApprovals && <PrintApprovalBoxes profile={profile} family="invoice" />}
    <PrintFooter profile={profile} kind="invoice" family="invoice" />
  </article>;
}
