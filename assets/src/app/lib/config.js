// Server-provided configuration (see includes/Admin.php → config()).
export const C = window.FiloraConfig || {};
export const profile = C.profile || { ops: {} };

/** Is an operation allowed for the current user? */
export const can = (op) => !!(profile.ops && profile.ops[op]);

export const isAdmin = !!profile.isAdmin;
