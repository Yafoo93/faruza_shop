import api from "./api";

const contentTypes = {
  csv: "text/csv",
  excel: "application/vnd.ms-excel",
  pdf: "application/pdf",
};

function downloadBlob(data, filename, format) {
  const blob = new Blob([data], { type: contentTypes[format] || "application/octet-stream" });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

function extensionFor(format) {
  return format === "excel" ? "xls" : format;
}

export async function downloadSalesReport({ from, to, format }) {
  const res = await api.get("/reports/sales", {
    params: { from, to, format },
    responseType: "blob",
  });
  const suffix = `${from || "start"}-to-${to || "today"}`;
  downloadBlob(res.data, `sales-report-${suffix}.${extensionFor(format)}`, format);
}

export async function downloadReceipt(saleId, format = "pdf") {
  const res = await api.get(`/reports/sales/${saleId}/receipt`, {
    params: { format },
    responseType: "blob",
  });
  downloadBlob(res.data, `receipt-sale-${saleId}.${extensionFor(format)}`, format);
}
