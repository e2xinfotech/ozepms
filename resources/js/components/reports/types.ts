export type ValueType = 'text' | 'period' | 'date' | 'datetime' | 'int' | 'decimal' | 'money' | 'percent' | 'status' | 'ref' | 'mixed';

export interface ReportColumn { key: string; label: string; type: ValueType }
export interface ReportTable {
    key: string; title: string; columns: ReportColumn[]; rows: Record<string, unknown>[];
    totals: Record<string, unknown> | null; pagination: { page: number; per_page: number; total: number; last_page: number } | null;
}
export interface ReportKpi { key: string; label: string; type: ValueType; value: string | number; previous: string | number | null }
export interface ReportChart {
    labels: string[]; label_type: 'period' | 'text'; bars: number[]; line: number[];
    bar_label: string; line_label: string; bar_type: ValueType; line_type: ValueType;
}
export interface ReportData { summary: ReportKpi[]; chart: ReportChart | null; tables: ReportTable[] }
export interface CatalogItem { key: string; slug: string; icon: string; group: string; title: string; description: string; url: string }
export interface ReportFilters {
    from: string; to: string; date: string; group: string; room_type: string | null; source: string | null; status: string | null;
    basis: string; pickup_days: number; page: number;
}
export interface Option { value: string; label: string }
export interface ReportOptions { room_types: Option[]; sources: Option[]; statuses: Option[]; max_days: number; currency: string; today: string }
