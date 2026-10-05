/** Downloads rows as a CSV file (Excel-friendly UTF-8 with BOM). */
export function downloadCsv(filename: string, header: string[], rows: (string | number | null | undefined)[][]): void {
    const cell = (v: string | number | null | undefined) => {
        const text = v === null || v === undefined ? '' : String(v);
        return /[",\n;]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
    };
    const body = [header, ...rows].map((r) => r.map(cell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['﻿' + body], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
