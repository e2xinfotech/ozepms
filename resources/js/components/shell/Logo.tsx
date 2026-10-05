export function Logo({ tagline = true, className }: { tagline?: boolean; className?: string }) {
    return (
        <div className={className}>
            <div className="logo-word">Oze<span className="logo-accent">PMS</span></div>
            {tagline && <div className="logo-tag">Property Management System</div>}
        </div>
    );
}
