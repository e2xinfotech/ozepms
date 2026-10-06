import { useMemo, useState } from 'react';
import { Alert, Button, Checkbox, Drawer, FormSection, Input } from '@/components/ui';
import { ApiError, http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { AriFields, blankValues, toPayload, type AriValues } from './AriFields';
import type { SaveResult } from './CellEditor';
import type { Option } from './types';

interface Props {
    open: boolean;
    onClose: () => void;
    onSaved: (res: SaveResult) => void;
    roomTypes: Option[];
    ratePlans: Option[];
    initial: { from: string; to: string; roomTypes: string[]; ratePlans: string[] };
    minDate: string;
}

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

/** "Bulk Update": a date range, weekdays, room types and rate plans, and the values to set. */
export function BulkUpdateDrawer({ open, onClose, onSaved, roomTypes, ratePlans, initial, minDate }: Props) {
    const [from, setFrom] = useState(initial.from);
    const [to, setTo] = useState(initial.to);
    const [weekdays, setWeekdays] = useState<number[]>([]);
    const [rts, setRts] = useState<string[]>(initial.roomTypes);
    const [plans, setPlans] = useState<string[]>(initial.ratePlans);
    const [values, setValues] = useState<AriValues>(blankValues());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const nights = useMemo(() => {
        if (!from || !to || to < from) return 0;
        let n = 0;
        for (let d = new Date(from + 'T00:00:00Z'); d <= new Date(to + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + 1)) {
            const iso = d.getUTCDay() === 0 ? 7 : d.getUTCDay();
            if (weekdays.length === 0 || weekdays.includes(iso)) n++;
        }
        return n;
    }, [from, to, weekdays]);

    const flip = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<SaveResult>(propertyApiUrl('/calendar/bulk'), {
                date_from: from, date_to: to, weekdays, room_type_ids: rts, rate_plan_ids: plans, ...toPayload(values),
            });
            onSaved(res);
        } catch (e) {
            setError(e instanceof ApiError ? e : new ApiError((e as Error).message, 0, 'CLIENT'));
        } finally {
            setBusy(false);
        }
    };

    const f = (name: string) => error?.field(name) ?? null;
    const listError = (prefix: string) => error ? Object.entries(error.fields).find(([k]) => k === prefix || k.startsWith(prefix + '.'))?.[1]?.[0] ?? null : null;
    const general = error && (f('fields') ?? f('targets') ?? f('products') ?? f('sell_limit') ?? (Object.keys(error.fields).length === 0 ? error.message : null));

    return (
        <Drawer open={open} onClose={onClose} size="lg" title={t('calendar.bulk.title')} footer={<>
            <span className="muted num cal-bulk-count">{t('calendar.bulk.summary', { nights })}</span>
            <Button variant="secondary" onClick={onClose}>{t('calendar.edit.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} disabled={nights === 0} onClick={submit}>{t('calendar.bulk.apply')}</Button>
        </>}>
            {general && <Alert tone="danger">{general}</Alert>}
            <FormSection title={t('calendar.bulk.dates')}>
                <div className="form-grid">
                    <Input fieldClass="span-6" type="date" label={t('calendar.fields.date_from')} value={from} min={minDate} error={f('date_from')} onChange={(e) => setFrom(e.target.value)} />
                    <Input fieldClass="span-6" type="date" label={t('calendar.fields.date_to')} value={to} min={from || minDate} error={f('date_to')} onChange={(e) => setTo(e.target.value)} />
                    <div className="field span-12">
                        <span className="field-label">{t('calendar.bulk.weekdays')}</span>
                        <div className="cal-weekdays" role="group" aria-label={t('calendar.bulk.weekdays')}>
                            <button type="button" className={weekdays.length === 0 ? 'active' : ''} aria-pressed={weekdays.length === 0} onClick={() => setWeekdays([])}>{t('calendar.bulk.all_days')}</button>
                            {WEEKDAYS.map((d) => (
                                <button key={d} type="button" className={weekdays.includes(d) ? 'active' : ''} aria-pressed={weekdays.includes(d)}
                                    onClick={() => setWeekdays((w) => (w.includes(d) ? w.filter((x) => x !== d) : [...w, d].sort()))}>
                                    {t(`calendar.weekday_short.${d}`)}
                                </button>
                            ))}
                        </div>
                        {listError('weekdays') && <div className="field-error" role="alert">{listError('weekdays')}</div>}
                    </div>
                </div>
            </FormSection>
            <FormSection title={t('calendar.bulk.targets')} description={t('calendar.bulk.all_room_types_hint')}>
                <div className="form-grid">
                    <div className="field span-6">
                        <span className="field-label">{t('calendar.bulk.room_types')}</span>
                        <div className="cal-checklist">
                            {roomTypes.map((o) => <Checkbox key={o.value} label={o.label} checked={rts.includes(o.value)} onChange={() => setRts((l) => flip(l, o.value))} />)}
                        </div>
                        {listError('room_type_ids') && <div className="field-error" role="alert">{listError('room_type_ids')}</div>}
                    </div>
                    <div className="field span-6">
                        <span className="field-label">{t('calendar.bulk.rate_plans')}</span>
                        <div className="cal-checklist">
                            {ratePlans.map((o) => <Checkbox key={o.value} label={o.label} checked={plans.includes(o.value)} onChange={() => setPlans((l) => flip(l, o.value))} />)}
                        </div>
                        {listError('rate_plan_ids') && <div className="field-error" role="alert">{listError('rate_plan_ids')}</div>}
                    </div>
                </div>
            </FormSection>
            <FormSection title={t('calendar.bulk.values')} description={t('calendar.edit.empty_hint')}>
                <AriFields values={values} onChange={setValues} error={error} roomType product />
            </FormSection>
        </Drawer>
    );
}
