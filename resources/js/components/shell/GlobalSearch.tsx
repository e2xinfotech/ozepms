import { useEffect, useMemo, useRef, useState } from 'react';
import { Badge, Icon } from '@/components/ui';
import { date } from '@/lib/format';
import { http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { useClickOutside, useDebounced } from '@/lib/use';

interface Results {
    reservations: { id: string; ref: string; guest: string; check_in: string; check_out: string; status: string }[];
    guests: { id: string; number: string; name: string; email: string | null; phone: string | null }[];
    rooms: { id: string; name: string; room_type: string; housekeeping: string; reservation: { id: string; ref: string; guest: string; status: string } | null }[];
}
interface Item { key: string; href: string; icon: string; title: string; sub: string; badge?: string }

/**
 * Top bar search (Ctrl + K): booking ID, guest name, e-mail, phone or room number. The server
 * runs only the matching indexed query; up to 8 results per group. Arrow keys + Enter open a result.
 */
export function GlobalSearch({ placeholder }: { placeholder: string }) {
    const [q, setQ] = useState('');
    const dq = useDebounced(q.trim(), 150);
    const [res, setRes] = useState<Results | null>(null);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const box = useClickOutside<HTMLDivElement>(() => setOpen(false));

    useEffect(() => {
        const h = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); input.current?.focus(); setOpen(true); }
        };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, []);

    useEffect(() => {
        if (dq.length < 2) { setRes(null); return; }
        let alive = true;
        setLoading(true);
        http.get<Results>(propertyApiUrl('/search'), { q: dq })
            .then((r) => { if (alive) { setRes(r); setActive(0); } })
            .catch(() => alive && setRes({ reservations: [], guests: [], rooms: [] }))
            .finally(() => alive && setLoading(false));
        return () => { alive = false; };
    }, [dq]);

    const groups = useMemo((): { label: string; items: Item[] }[] => {
        if (!res) return [];
        const all: { label: string; items: Item[] }[] = [
            { label: t('reservations.search_box.reservations'), items: res.reservations.map((r) => ({ key: `r${r.id}`, href: propertyUrl(`/reservations/${r.id}`), icon: 'calendar-check', title: `${r.ref} · ${r.guest}`, sub: `${date(r.check_in)} – ${date(r.check_out)}`, badge: r.status })) },
            { label: t('reservations.search_box.guests'), items: res.guests.map((g) => ({ key: `g${g.id}`, href: propertyUrl(`/guests?selected=${g.id}`), icon: 'user', title: g.name, sub: [g.number, g.email, g.phone].filter(Boolean).join(' · ') })) },
            { label: t('reservations.search_box.rooms'), items: res.rooms.map((u) => ({ key: `u${u.id}`, href: u.reservation ? propertyUrl(`/reservations/${u.reservation.id}`) : propertyUrl(`/rooms?selected=${u.id}`), icon: 'door-open', title: `${u.name} · ${u.room_type}`, sub: u.reservation ? `${u.reservation.ref} · ${u.reservation.guest}` : t('reservations.search_box.vacant'), badge: u.reservation?.status })) },
        ];
        return all.filter((g) => g.items.length > 0);
    }, [res]);
    const flat = groups.flatMap((g) => g.items);

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') { setOpen(false); input.current?.blur(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(flat.length - 1, a + 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
        else if (e.key === 'Enter' && flat[active]) { e.preventDefault(); window.location.href = flat[active].href; }
    };

    return (
        <div className="topbar-search global-search" ref={box}>
            <div className="control">
                <Icon name={loading ? 'loader' : 'search'} size={18} className={loading ? 'control-icon spin' : 'control-icon'} />
                <input ref={input} type="search" placeholder={placeholder} aria-label={t('ui.search')} value={q} autoComplete="off"
                    onChange={(e) => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)} onKeyDown={onKey}
                    role="combobox" aria-expanded={open && dq.length >= 2} aria-controls="global-search-results" />
                <kbd className="kbd" aria-hidden>Ctrl K</kbd>
            </div>
            {open && dq.length >= 2 && res && <div className="menu gs-results" id="global-search-results" role="listbox">
                {flat.length === 0 && <div className="empty" style={{ padding: 16 }}><span>{t('reservations.search_box.none', { q: dq })}</span><span className="text-xs muted">{t('reservations.search_box.hint')}</span></div>}
                {groups.map((g) => (
                    <div key={g.label}>
                        <div className="menu-title">{g.label}</div>
                        {g.items.map((it) => {
                            const i = flat.indexOf(it);
                            return (
                                <a key={it.key} href={it.href} className={`menu-item${i === active ? ' active' : ''}`} role="option" aria-selected={i === active} onMouseEnter={() => setActive(i)}>
                                    <Icon name={it.icon} size={16} />
                                    <span className="grow gs-text"><span className="strong">{it.title}</span><span className="text-xs muted">{it.sub}</span></span>
                                    {it.badge && <Badge size="sm" status={it.badge}>{t(`reservations.status.${it.badge}`)}</Badge>}
                                </a>
                            );
                        })}
                    </div>
                ))}
            </div>}
        </div>
    );
}
