import type { UserData } from '@/api/account/types';
import type { SiteSettings } from '@/api/settings/types';
import type { SiteExtensionEntry } from '@/extensions/loader';

interface ExtendedWindow extends Window {
    SiteConfiguration?: SiteSettings & { extensions?: SiteExtensionEntry[] };
    PterodactylUser?: {
        uuid: string;
        username: string;
        email: string;
        root_admin: boolean;
        use_totp: boolean;
        language: string;
        updated_at: string;
        created_at: string;
    };
}

export const getBootstrapUser = (): UserData | undefined => {
    const win = (globalThis as typeof globalThis & { window?: ExtendedWindow }).window;
    const user = win?.PterodactylUser;

    return user
        ? {
              uuid: user.uuid,
              username: user.username,
              email: user.email,
              language: user.language,
              rootAdmin: user.root_admin,
              useTotp: user.use_totp,
              createdAt: new Date(user.created_at),
              updatedAt: new Date(user.updated_at),
          }
        : undefined;
};

let sessionEnded = false;

export const endBootstrapSession = (): void => {
    sessionEnded = true;
};

export const hasBootstrapSession = (): boolean => !sessionEnded && !!getBootstrapUser()?.uuid;

export const getBootstrapSiteSettings = (): SiteSettings | undefined =>
    (globalThis as typeof globalThis & { window?: ExtendedWindow }).window?.SiteConfiguration;

export const getBootstrapExtensions = (): SiteExtensionEntry[] =>
    (globalThis as typeof globalThis & { window?: ExtendedWindow }).window?.SiteConfiguration?.extensions ?? [];
