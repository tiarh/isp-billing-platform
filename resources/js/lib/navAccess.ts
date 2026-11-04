/**
 * Sidebar visibility aligned with Laravel middleware:
 * - auth          → any logged-in user (incl. teknisi)
 * - admin         → EnsureAdmin (superadmin | admin | noc)
 * - global_admin  → EnsureGlobalAdmin (superadmin | noc | unscoped admin)
 * - superadmin    → EnsureSuperAdmin
 *
 * Prevents 403 from menus the role cannot open.
 */

export type NavAccess = 'auth' | 'admin' | 'admin_only' | 'global_admin' | 'global_admin_only' | 'superadmin';

export type AuthUserLike = {
    role?: string;
    scope?: string;
};

export function isSuperAdminUser(user: AuthUserLike): boolean {
    return user.role === 'superadmin' || user.scope === 'superadmin';
}

/** Matches User::isAdminLike() */
export function isAdminLikeUser(user: AuthUserLike): boolean {
    return isSuperAdminUser(user) || user.role === 'admin' || user.role === 'noc';
}

/** Matches User::isGlobalAdmin() + inertia scope (noc | unscoped admin | superadmin) */
export function isGlobalAdminUser(user: AuthUserLike): boolean {
    if (isSuperAdminUser(user)) {
        return true;
    }
    if (user.role === 'noc') {
        return true;
    }
    return user.scope === 'global_admin';
}

export function canSeeNavAccess(user: AuthUserLike | null | undefined, access: NavAccess = 'auth'): boolean {
    if (!user) {
        return false;
    }
    switch (access) {
        case 'superadmin':
            return isSuperAdminUser(user);
        case 'global_admin':
            return isGlobalAdminUser(user);
        case 'global_admin_only':
            // superadmin | admin without area scope (NOC and area-scoped admins excluded)
            return isSuperAdminUser(user) || (user.role === 'admin' && user.scope === 'global_admin');
        case 'admin_only':
            return isSuperAdminUser(user) || user.role === 'admin';
        case 'admin':
            return isAdminLikeUser(user);
        case 'auth':
        default:
            return true;
    }
}

export function roleDisplayLabel(user: AuthUserLike): string {
    if (isSuperAdminUser(user)) {
        return 'Superadmin';
    }
    if (user.role === 'noc') {
        return 'NOC';
    }
    if (user.role === 'teknisi') {
        return 'Teknisi';
    }
    if (user.scope === 'global_admin') {
        return 'Global Admin';
    }
    if (user.scope === 'scoped_admin') {
        return 'Scoped Admin';
    }
    return user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'User';
}
