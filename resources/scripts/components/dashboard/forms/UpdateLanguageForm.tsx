import React from 'react';
import { useStore } from '@tanstack/react-form';
import { useAppForm, Form } from '@/components/form';
import { useTranslation } from 'react-i18next';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { useLanguage } from '@/hooks/useLanguage';

export default function UpdateLanguageForm() {
    const { t } = useTranslation();
    const { currentLanguage, languages, changeLanguage, isUpdating } = useLanguage();

    const form = useAppForm({
        defaultValues: { language: currentLanguage },
        onSubmit: async ({ value }) => {
            await changeLanguage(value.language);
        },
    });

    const isSubmitting = useStore(form.store, (state) => state.isSubmitting) || isUpdating;

    const options = Object.entries(languages).map(([value, label]) => ({
        value,
        label,
    }));

    return (
        <React.Fragment>
            <SpinnerOverlay size={'large'} visible={isSubmitting} />
            <Form form={form} className={'m-0'}>
                <form.AppField name={'language'}>
                    {(field) => (
                        <field.SelectField
                            id={'user_language'}
                            label={'Language'}
                            options={options}
                            description={'Choose the language used throughout the panel interface.'}
                        />
                    )}
                </form.AppField>
                <div className={'mt-6'}>
                    <form.AppForm>
                        <form.SubmitButton disabled={isUpdating}>{t('save') || 'Save Language'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </React.Fragment>
    );
}
