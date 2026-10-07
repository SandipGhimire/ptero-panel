import { useContext } from 'react';
import { useTranslation } from 'react-i18next';
import i18n from '@/i18n';
import { ExtensionContext } from '@/extensions/context';

export interface ExtensionTranslation {
    locale: string;
    ready: boolean;
    t(key: string, values?: Record<string, string | number>): string;
}

export type ExtensionResourceBundle = Record<string, Record<string, string>>;

export interface RegisterExtensionTranslationsOptions {
    extensionId: string;
    group?: string;
    resources: ExtensionResourceBundle;
}

/**
 * Register translation bundles client-side for an extension.
 * This allows extensions to provide native translations directly in code
 * without requiring network fetches to the backend for translation dictionaries.
 *
 * Example:
 * ```ts
 * registerExtensionTranslations({
 *     extensionId: 'my-plugin',
 *     resources: {
 *         en: { 'welcome': 'Welcome to my plugin!' },
 *         de: { 'welcome': 'Willkommen in meinem Plugin!' },
 *     }
 * });
 * ```
 */
export function registerExtensionTranslations({
    extensionId,
    group = 'messages',
    resources,
}: RegisterExtensionTranslationsOptions): void {
    if (!/^[a-z][a-z0-9_-]{0,63}$/.test(group)) throw new Error('Invalid extension translation group.');

    const namespace = `ext-${extensionId}::${group}`;

    for (const [locale, bundle] of Object.entries(resources)) {
        i18n.addResourceBundle(locale, namespace, bundle, true, true);
    }
}

/** resources/lang/<locale>/<group>.php, registered with loadExtensionTranslations() or registerExtensionTranslations(). */
export function useExtensionTranslation(group = 'messages'): ExtensionTranslation {
    const mount = useContext(ExtensionContext);
    if (!mount) throw new Error('Extension translations require an extension mount.');
    if (!/^[a-z][a-z0-9_-]{0,63}$/.test(group)) throw new Error('Invalid extension translation group.');
    const namespace = `ext-${mount.extensionId}::${group}`;
    const { t, i18n, ready } = useTranslation(namespace, { useSuspense: false });
    return { locale: i18n.language, ready, t: (key, values) => t(key, { ...values, nsSeparator: false }) };
}
