import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrentUser } from '@/api/account/queries';
import { useClientLanguages, useUpdateAccountLanguage } from '@/api/account/languages';

export function useLanguage() {
    const { i18n } = useTranslation();
    const currentUser = useCurrentUser();
    const { data: languages, isLoading } = useClientLanguages();
    const updateAccountLanguage = useUpdateAccountLanguage();
    const [localLang, setLocalLang] = useState<string>(i18n.language || 'en');

    const currentLanguage = currentUser?.language || i18n.language || localLang;

    const changeLanguage = useCallback(
        async (lang: string) => {
            setLocalLang(lang);
            void i18n.changeLanguage(lang);
            try {
                localStorage.setItem('panel_language', lang);
            } catch {
                // Ignore storage errors
            }

            if (currentUser?.uuid) {
                await updateAccountLanguage.mutateAsync({ body: { language: lang } });
            }
        },
        [currentUser?.uuid, i18n, updateAccountLanguage]
    );

    return {
        currentLanguage,
        languages: languages ?? { en: 'English' },
        isLoading,
        changeLanguage,
        isUpdating: updateAccountLanguage.isPending,
    };
}
