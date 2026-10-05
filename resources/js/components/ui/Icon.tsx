import {
    Activity, AlertTriangle, ArrowLeft, ArrowRight, ArrowUpDown, BadgePercent, Ban, Bed, BedDouble, Bell, Building2,
    CalendarCheck, CalendarDays, CalendarRange, ChartColumn, Check, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight,
    ChevronUp, CircleAlert, CircleX, Clock, Copy, CreditCard, DoorOpen, Download, Eye, EyeOff, FileText, Filter, Globe,
    Hotel, House, Info, KeyRound, Link, Loader2, Lock, LogIn, LogOut, Mail, MapPin, Menu, Moon, MoreHorizontal,
    MoreVertical, Network, Pause, Pencil, Phone, Plus, Printer, Receipt, RefreshCw, Save, ScrollText, Search, Settings,
    Shield, ShieldCheck, Star, Tags, Trash2, TrendingDown, TrendingUp, Upload, User, UserCheck, UserCog, UserPlus, Users,
    Utensils, Wallet, X, Smartphone, Building, Layers, CircleDollarSign, BriefcaseBusiness, Sparkles, Wrench,
    DoorClosed, BedSingle, Baby, UserRound, Percent, Gift, Megaphone, Ticket, Hash, Calculator, Banknote, IndianRupee, Landmark, FileDown, FileUp, Send, StickyNote, History, ArrowLeftRight, Repeat, Brush, SprayCan, ClipboardCheck, Hammer, Mountain, Waves, Coffee, UtensilsCrossed, Wine, Shirt, Thermometer, Fan, Refrigerator, Microwave, SquareParking, Dumbbell, Accessibility, Ruler, CigaretteOff, Image, Images, CalendarX, CalendarPlus, CalendarClock, List, ListFilter, LayoutGrid, ChevronsLeft, ChevronsRight, CircleCheck, Minus, Plane, QrCode, Bath, ShowerHead, Wifi, Tv, Snowflake, Car, Sun, Flame, CircleSlash, Archive, Lightbulb, Vault, Armchair, WashingMachine, Heater, ConciergeBell,
    type LucideIcon,
} from 'lucide-react';

/**
 * Central icon registry. Pages refer to icons by name so the menu config on the
 * server can choose icons too. Add new icons here (imported individually to keep bundles small).
 */
const registry: Record<string, LucideIcon> = {
    activity: Activity, 'alert-triangle': AlertTriangle, 'arrow-left': ArrowLeft, 'arrow-right': ArrowRight,
    'arrow-up-down': ArrowUpDown, 'badge-percent': BadgePercent, ban: Ban, bed: Bed, 'bed-double': BedDouble, bell: Bell,
    'building-2': Building2, building: Building, 'calendar-check': CalendarCheck, 'calendar-days': CalendarDays,
    'calendar-range': CalendarRange, 'chart-column': ChartColumn, check: Check, 'check-circle': CheckCircle2,
    'chevron-down': ChevronDown, 'chevron-left': ChevronLeft, 'chevron-right': ChevronRight, 'chevron-up': ChevronUp,
    'circle-alert': CircleAlert, 'circle-x': CircleX, clock: Clock, copy: Copy, 'credit-card': CreditCard,
    'door-open': DoorOpen, download: Download, eye: Eye, 'eye-off': EyeOff, 'file-text': FileText, filter: Filter,
    globe: Globe, hotel: Hotel, house: House, info: Info, key: KeyRound, link: Link, loader: Loader2, lock: Lock,
    'log-in': LogIn, 'log-out': LogOut, mail: Mail, 'map-pin': MapPin, menu: Menu, moon: Moon,
    'more-horizontal': MoreHorizontal, 'more-vertical': MoreVertical, network: Network, pause: Pause, pencil: Pencil,
    phone: Phone, plus: Plus, printer: Printer, receipt: Receipt, refresh: RefreshCw, save: Save,
    'scroll-text': ScrollText, search: Search, settings: Settings, shield: Shield, 'shield-check': ShieldCheck,
    star: Star, tags: Tags, trash: Trash2, 'trending-down': TrendingDown, 'trending-up': TrendingUp, upload: Upload,
    user: User, 'user-check': UserCheck, 'user-cog': UserCog, 'user-plus': UserPlus, users: Users, utensils: Utensils,
    wallet: Wallet, x: X, smartphone: Smartphone, layers: Layers, 'circle-dollar-sign': CircleDollarSign,
    briefcase: BriefcaseBusiness, sparkles: Sparkles, wrench: Wrench,
    'door-closed': DoorClosed, 'bed-single': BedSingle, 'baby': Baby, 'user-round': UserRound, 'percent': Percent, 'gift': Gift, 'megaphone': Megaphone, 'ticket': Ticket, 'hash': Hash, 'calculator': Calculator, 'banknote': Banknote, 'indian-rupee': IndianRupee, 'landmark': Landmark, 'file-down': FileDown, 'file-up': FileUp, 'send': Send, 'sticky-note': StickyNote, 'history': History, 'arrow-left-right': ArrowLeftRight, 'repeat': Repeat, 'brush': Brush, 'spray-can': SprayCan, 'clipboard-check': ClipboardCheck, 'hammer': Hammer, 'mountain': Mountain, 'waves': Waves, 'coffee': Coffee, 'utensils-crossed': UtensilsCrossed, 'wine': Wine, 'shirt': Shirt, 'thermometer': Thermometer, 'fan': Fan, 'refrigerator': Refrigerator, 'microwave': Microwave, 'square-parking': SquareParking, 'dumbbell': Dumbbell, 'accessibility': Accessibility, 'ruler': Ruler, 'cigarette-off': CigaretteOff, 'image': Image, 'images': Images, 'calendar-x': CalendarX, 'calendar-plus': CalendarPlus, 'calendar-clock': CalendarClock, 'list': List, 'list-filter': ListFilter, 'layout-grid': LayoutGrid, 'chevrons-left': ChevronsLeft, 'chevrons-right': ChevronsRight, 'circle-check': CircleCheck, 'minus': Minus, 'plane': Plane, 'qr-code': QrCode, 'bath': Bath, 'shower-head': ShowerHead, 'wifi': Wifi, 'tv': Tv, 'snowflake': Snowflake, 'car': Car, 'sun': Sun, 'flame': Flame, 'circle-slash': CircleSlash, 'archive': Archive, 'lightbulb': Lightbulb, 'vault': Vault, 'armchair': Armchair, 'washing-machine': WashingMachine, 'heater': Heater, 'concierge-bell': ConciergeBell,
};

export interface IconProps {
    name: string;
    size?: number;
    className?: string;
    strokeWidth?: number;
    title?: string;
}

export function Icon({ name, size = 18, className, strokeWidth = 1.8, title }: IconProps) {
    const Cmp = registry[name] ?? Info;
    return <Cmp size={size} className={className} strokeWidth={strokeWidth} aria-hidden={title ? undefined : true} aria-label={title} />;
}
