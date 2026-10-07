import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { notifyHttpError } from '@/plugins/notifications';
import { setCurrentUserQueryData } from '@/api/account/queries';
import {
    clientListLanguagesOptions,
    clientUpdateAccountLanguageMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { ClientListLanguagesResponse } from '@/api/generated';
import i18n from '@/i18n';

export type ClientLanguages = ClientListLanguagesResponse;

export const clientLanguagesQueryOptions = () => clientListLanguagesOptions();

export const useClientLanguages = () => useQuery(clientLanguagesQueryOptions());

export const useUpdateAccountLanguage = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientUpdateAccountLanguageMutation(),
        onSuccess: (_data, variables) => {
            const language = variables.body.language;
            setCurrentUserQueryData(queryClient, { language });
            void i18n.changeLanguage(language);
            try {
                localStorage.setItem('panel_language', language);
            } catch {
                // Ignore storage errors
            }
            toast.success('Language preference updated');
        },
        onError: (error) => notifyHttpError(error, 'Unable to update language preference'),
    });
};
