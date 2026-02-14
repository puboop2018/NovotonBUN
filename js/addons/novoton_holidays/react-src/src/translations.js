/**
 * Novoton Booking Engine - Translation strings
 *
 * Bilingual support: English (en) and Romanian (ro).
 * Calendar month/weekday names are kept here; UI label strings
 * are read at runtime from window.NovotonTranslations (injected by
 * Smarty templates) so the store admin can override them via the
 * CS-Cart language system.
 */

export const MONTHS_EN = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

export const MONTHS_RO = [
    'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie',
    'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie',
];

export const MONTHS_SHORT_EN = [
    'Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.',
    'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.',
];

export const MONTHS_SHORT_RO = [
    'Ian.', 'Febr.', 'Mart.', 'Apr.', 'Mai', 'Iun.',
    'Iul.', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.',
];

export const WEEKDAYS_EN = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
export const WEEKDAYS_RO = ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'];

export const DAYS_SHORT_EN = ['Sun.', 'Mon.', 'Tue.', 'Wed.', 'Thu.', 'Fri.', 'Sat.'];
export const DAYS_SHORT_RO = ['Dum.', 'Lun.', 'Mar.', 'Mie.', 'Joi', 'Vin.', 'Sâm.'];
