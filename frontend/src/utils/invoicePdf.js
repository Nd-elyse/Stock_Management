import { jsPDF } from 'jspdf';
import { autoTable } from 'jspdf-autotable';

/* ============================================================
   Client-side "Download Invoice" PDF generator for the public
   repair-status lookup. No backend PDF endpoint exists, so this
   builds a clean, professional invoice straight from the JSON
   already returned by POST /api/track-repair.
   ============================================================ */

const BLUE = [37, 99, 235];
const DARK = [15, 23, 42];
const MUTED = [90, 106, 122];
const BORDER = [226, 232, 240];
const SUCCESS = [22, 163, 74];
const WARNING = [217, 119, 6];
const DANGER = [220, 38, 38];

function money(n) {
  const v = Number(n || 0);
  return `${v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} RWF`;
}

function fmtDate(d) {
  if (!d) return '-';
  const date = new Date(d);
  if (Number.isNaN(date.getTime())) return String(d);
  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function statusColor(status) {
  if (status === 'Paid') return SUCCESS;
  if (status === 'Partial') return WARNING;
  return DANGER;
}

/**
 * @param {object} result - the `data` object returned by the track-repair API
 *   ({ vehicle, job, history, parts_used, estimate })
 */
export function downloadRepairInvoice(result) {
  const vehicle = result?.vehicle || {};
  const job = result?.job || {};
  const estimate = result?.estimate;
  const partsUsed = result?.parts_used || [];
  if (!estimate) return false;

  const doc = new jsPDF({ unit: 'pt', format: 'a4' });
  const pageW = doc.internal.pageSize.getWidth();
  const margin = 40;

  /* ---------------- Header ---------------- */
  doc.setFillColor(...BLUE);
  doc.circle(margin + 15, 44, 15, 'F');
  doc.setTextColor(255, 255, 255);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.text('GM', margin + 15, 48, { align: 'center' });

  doc.setTextColor(...DARK);
  doc.setFontSize(16);
  doc.text('GarageManager', margin + 40, 41);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...MUTED);
  doc.text('Smart Garage Services & Stock Management', margin + 40, 55);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(22);
  doc.setTextColor(...DARK);
  doc.text('INVOICE', pageW - margin, 38, { align: 'right' });

  const invoiceNo = `INV-${String(estimate.invoice_id || job.job_id || '000000').padStart(6, '0')}`;
  const metaRows = [
    ['Invoice No:', invoiceNo],
    ['Invoice Date:', fmtDate(estimate.invoice_date || job.start_date)],
    ['Job Reference:', `JOB-${job.job_id || '-'}`],
  ];
  let metaY = 56;
  doc.setFontSize(9.5);
  metaRows.forEach(([label, value]) => {
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(...MUTED);
    doc.text(label, pageW - margin - 150, metaY);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(...DARK);
    doc.text(String(value), pageW - margin, metaY, { align: 'right' });
    metaY += 13;
  });

  doc.setDrawColor(...BORDER);
  doc.setLineWidth(1);
  doc.line(margin, 82, pageW - margin, 82);

  /* ---------------- Info boxes ---------------- */
  const boxTop = 98;
  const boxH = 106;
  const gap = 18;
  const boxW = (pageW - margin * 2 - gap) / 2;

  const drawInfoBox = (x, title, rows) => {
    doc.setDrawColor(...BORDER);
    doc.setLineWidth(1);
    doc.roundedRect(x, boxTop, boxW, boxH, 6, 6, 'S');
    doc.setFillColor(...BLUE);
    doc.rect(x + 1, boxTop + 1, boxW - 2, 20, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9.5);
    doc.text(title, x + 10, boxTop + 14);

    let y = boxTop + 38;
    rows.forEach(([label, value]) => {
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9);
      doc.setTextColor(...MUTED);
      doc.text(label, x + 10, y);
      doc.setFont('helvetica', 'bold');
      doc.setTextColor(...DARK);
      doc.text(String(value || '-'), x + 82, y, { maxWidth: boxW - 92 });
      y += 16;
    });
  };

  drawInfoBox(margin, 'CUSTOMER INFORMATION', [
    ['Name:', vehicle.owner_name],
    ['Phone:', vehicle.owner_phone],
    ['Email:', vehicle.owner_email],
  ]);
  drawInfoBox(margin + boxW + gap, 'VEHICLE INFORMATION', [
    ['Vehicle:', [vehicle.manufacturer, vehicle.model].filter(Boolean).join(' ') || '-'],
    ['Plate No:', vehicle.plate_number],
    ['Year:', vehicle.year],
    ['Mechanic:', job.mechanic_name],
  ]);

  let cursorY = boxTop + boxH + 26;

  /* ---------------- Labour table ---------------- */
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.setTextColor(...DARK);
  doc.text('1. Labour Charges', margin, cursorY);

  autoTable(doc, {
    startY: cursorY + 8,
    margin: { left: margin, right: margin },
    head: [['Description', 'Amount (RWF)']],
    body: [['Labour & Service Charges', money(estimate.labour_charges)]],
    theme: 'plain',
    headStyles: { fillColor: BLUE, textColor: 255, fontSize: 9, cellPadding: 6 },
    bodyStyles: { fontSize: 9.5, textColor: DARK, cellPadding: 6 },
    columnStyles: { 1: { halign: 'right' } },
    styles: { lineColor: BORDER, lineWidth: 0.75 },
  });
  cursorY = doc.lastAutoTable.finalY + 22;

  /* ---------------- Spare parts table ---------------- */
  const items = (estimate.items && estimate.items.length)
    ? estimate.items
    : partsUsed.map((p) => ({ part_name: p.part_name, quantity: p.quantity, price: null }));

  if (items.length) {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(...DARK);
    doc.text('2. Spare Parts', margin, cursorY);

    autoTable(doc, {
      startY: cursorY + 8,
      margin: { left: margin, right: margin },
      head: [['Part', 'Qty', 'Unit Price (RWF)', 'Amount (RWF)']],
      body: items.map((i) => [
        i.part_name || 'Spare part',
        String(i.quantity ?? '-'),
        i.price != null ? money(i.price) : '-',
        i.price != null ? money(i.price * (i.quantity || 1)) : '-',
      ]),
      theme: 'plain',
      headStyles: { fillColor: BLUE, textColor: 255, fontSize: 9, cellPadding: 6 },
      bodyStyles: { fontSize: 9.5, textColor: DARK, cellPadding: 6 },
      columnStyles: { 1: { halign: 'center' }, 2: { halign: 'right' }, 3: { halign: 'right' } },
      styles: { lineColor: BORDER, lineWidth: 0.75 },
    });
    cursorY = doc.lastAutoTable.finalY + 26;
  }

  /* ---------------- Totals ---------------- */
  if (cursorY > doc.internal.pageSize.getHeight() - 200) {
    doc.addPage();
    cursorY = 50;
  }

  const totalsW = 250;
  const totalsX = pageW - margin - totalsW;
  const totalRows = [
    ['Labour Charges', money(estimate.labour_charges)],
    ['Spare Parts', money(estimate.spare_parts_cost)],
    ['Subtotal', money((Number(estimate.labour_charges) || 0) + (Number(estimate.spare_parts_cost) || 0))],
    [`Tax${estimate.tax_rate ? ` (${estimate.tax_rate}%)` : ''}`, money(estimate.taxes)],
    ['Discount', `-${money(estimate.discounts)}`],
  ];
  let ty = cursorY;
  doc.setFontSize(9.5);
  totalRows.forEach(([label, value]) => {
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(...MUTED);
    doc.text(label, totalsX, ty);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(...DARK);
    doc.text(value, totalsX + totalsW, ty, { align: 'right' });
    ty += 17;
  });

  doc.setDrawColor(...BORDER);
  doc.line(totalsX, ty, totalsX + totalsW, ty);
  ty += 20;

  doc.setFillColor(...DARK);
  doc.rect(totalsX, ty - 15, totalsW, 27, 'F');
  doc.setTextColor(255, 255, 255);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.text('TOTAL AMOUNT', totalsX + 10, ty + 3);
  doc.text(money(estimate.total_amount), totalsX + totalsW - 10, ty + 3, { align: 'right' });
  ty += 34;

  if (estimate.payment_status) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9.5);
    doc.setTextColor(...MUTED);
    doc.text('Payment Status', totalsX, ty);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(...statusColor(estimate.payment_status));
    doc.text(estimate.payment_status, totalsX + totalsW, ty, { align: 'right' });
    ty += 16;

    if (estimate.payment_method) {
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(...MUTED);
      doc.text('Payment Method', totalsX, ty);
      doc.setFont('helvetica', 'bold');
      doc.setTextColor(...DARK);
      doc.text(String(estimate.payment_method), totalsX + totalsW, ty, { align: 'right' });
      ty += 16;
    }

    if (estimate.payment_status !== 'Paid') {
      const balance = (Number(estimate.total_amount) || 0) - (Number(estimate.total_paid) || 0);
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(...MUTED);
      doc.text('Balance Due', totalsX, ty);
      doc.setFont('helvetica', 'bold');
      doc.setTextColor(...DANGER);
      doc.text(money(balance), totalsX + totalsW, ty, { align: 'right' });
      ty += 16;
    }
  }

  /* ---------------- Footer ---------------- */
  const footerY = doc.internal.pageSize.getHeight() - 46;
  doc.setDrawColor(...BORDER);
  doc.line(margin, footerY - 16, pageW - margin, footerY - 16);
  doc.setFont('helvetica', 'italic');
  doc.setFontSize(9);
  doc.setTextColor(...MUTED);
  doc.text('Thank you for choosing GarageManager. We appreciate your business!', margin, footerY);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7.5);
  doc.text(`Generated via the GarageManager customer portal on ${new Date().toLocaleString()}`, margin, footerY + 14);

  const safePlate = (vehicle.plate_number || 'vehicle').replace(/\s+/g, '');
  doc.save(`Invoice-${safePlate}-JOB${job.job_id || ''}.pdf`);
  return true;
}
