import { number } from '@/lib/format';

/**
 * Lightweight SVG charts in the OzePMS style (no chart library).
 * Bars use the brand tint, lines the brand colour; axes are muted.
 */
export function BarLineChart({ labels, bars, line, barLabel, lineLabel, barMax, lineMax, height = 240, barFormat, lineFormat }: {
    labels: string[]; bars: number[]; line?: number[]; barLabel: string; lineLabel?: string;
    barMax?: number; lineMax?: number; height?: number; barFormat?: (v: number) => string; lineFormat?: (v: number) => string;
}) {
    const W = 760, H = height, padL = 46, padR = line ? 52 : 12, padT = 12, padB = 30;
    const iw = W - padL - padR, ih = H - padT - padB;
    const bMax = niceMax(barMax ?? Math.max(1, ...bars));
    const lMax = niceMax(lineMax ?? Math.max(1, ...(line ?? [1])));
    const step = iw / Math.max(1, labels.length);
    const bw = Math.min(42, step * 0.62);
    const ticks = [0, 0.2, 0.4, 0.6, 0.8, 1];
    // Small scales (e.g. no data yet) need a decimal so the ticks do not read "0, 0, 1, 1".
    const fb = barFormat ?? ((v: number) => number(v, bMax < 5 ? 1 : 0));
    const fl = lineFormat ?? ((v: number) => number(v, lMax < 5 ? 1 : 0));
    const pts = (line ?? []).map((v, i) => [padL + step * i + step / 2, padT + ih - (v / lMax) * ih] as const);

    return (
        <div className="chart-box">
            <svg viewBox={`0 0 ${W} ${H + 26}`} role="img" aria-label={barLabel}>
                {ticks.map((tk) => (
                    <g key={tk}>
                        <line x1={padL} x2={W - padR} y1={padT + ih - tk * ih} y2={padT + ih - tk * ih} stroke="var(--line)" />
                        <text x={padL - 8} y={padT + ih - tk * ih + 4} textAnchor="end">{fb(bMax * tk)}</text>
                        {line && <text x={W - padR + 8} y={padT + ih - tk * ih + 4}>{fl(lMax * tk)}</text>}
                    </g>
                ))}
                {bars.map((v, i) => {
                    const h = (v / bMax) * ih;
                    return <rect key={i} x={padL + step * i + (step - bw) / 2} y={padT + ih - h} width={bw} height={Math.max(h, 0)} rx={3} fill="#bcd5ff"><title>{`${labels[i]}: ${fb(v)}`}</title></rect>;
                })}
                {pts.length > 1 && <polyline points={pts.map((p) => p.join(',')).join(' ')} fill="none" stroke="var(--brand-600)" strokeWidth={2.2} />}
                {pts.map(([x, y], i) => <circle key={i} cx={x} cy={y} r={4.5} fill="var(--brand-600)" stroke="#fff" strokeWidth={1.5}><title>{`${labels[i]}: ${fl(line![i])}`}</title></circle>)}
                {labels.map((l, i) => <text key={l + i} x={padL + step * i + step / 2} y={H - 8} textAnchor="middle">{l}</text>)}
                <g transform={`translate(${W / 2 - 110}, ${H + 18})`}>
                    <circle cx={0} cy={-4} r={6} fill="#bcd5ff" /><text x={12} y={0} style={{ fill: 'var(--ink-2)', fontSize: 13 }}>{barLabel}</text>
                    {lineLabel && <><line x1={120} x2={140} y1={-4} y2={-4} stroke="var(--brand-600)" strokeWidth={2.4} /><circle cx={130} cy={-4} r={4.5} fill="var(--brand-600)" /><text x={148} y={0} style={{ fill: 'var(--ink-2)', fontSize: 13 }}>{lineLabel}</text></>}
                </g>
            </svg>
        </div>
    );
}

export function Donut({ segments, centerValue, centerLabel, size = 170 }: { segments: { label: string; value: number; color: string }[]; centerValue: string | number; centerLabel: string; size?: number }) {
    const total = segments.reduce((s, x) => s + x.value, 0);
    const r = 64, c = 2 * Math.PI * r;
    let offset = 0;
    return (
        <div className="donut-wrap">
            <svg width={size} height={size} viewBox="0 0 170 170" role="img" aria-label={centerLabel}>
                <circle cx={85} cy={85} r={r} fill="none" stroke="#edf1f7" strokeWidth={20} />
                {total > 0 && segments.map((s) => {
                    const len = (s.value / total) * c;
                    const el = <circle key={s.label} cx={85} cy={85} r={r} fill="none" stroke={s.color} strokeWidth={20} strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-offset} transform="rotate(-90 85 85)"><title>{`${s.label}: ${s.value}`}</title></circle>;
                    offset += len;
                    return el;
                })}
                <text x={85} y={84} textAnchor="middle" className="donut-center" style={{ fontSize: 24 }}>{centerValue}</text>
                <text x={85} y={106} textAnchor="middle" style={{ fontSize: 13, fill: 'var(--ink-2)' }}>{centerLabel}</text>
            </svg>
            <ul className="list-plain stack" style={{ gap: 12, flex: 1 }}>
                {segments.map((s) => (
                    <li key={s.label} className="row-between">
                        <span className="legend-item"><span className="legend-swatch" style={{ background: s.color, borderRadius: '50%' }} />{s.label}</span>
                        <span className="num strong">{s.value} <span className="muted">({total ? Math.round((s.value / total) * 1000) / 10 : 0}%)</span></span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function niceMax(v: number): number {
    if (v <= 0) return 1;
    const exp = Math.pow(10, Math.floor(Math.log10(v)));
    const n = v / exp;
    const nice = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
    return nice * exp;
}
