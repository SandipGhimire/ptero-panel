import { Search } from 'lucide-react';
import useEventListener from '@/plugins/useEventListener';
import SearchModal from '@/components/dashboard/search/SearchModal';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Icon from '@/components/elements/Icon';
import { cn } from '@/lib/cn';
import { useDialogState } from '@/components/elements/dialog';

import { useTranslation } from 'react-i18next';

interface Props {
    className?: string;
}

export default function SearchContainer({ className }: Props) {
    const { t } = useTranslation();
    const searchDialog = useDialogState();

    useEventListener('keydown', (e: KeyboardEvent) => {
        if (['input', 'textarea'].indexOf(((e.target as HTMLElement).tagName || 'input').toLowerCase()) < 0) {
            if (!searchDialog.open && e.metaKey && e.key.toLowerCase() === '/') {
                searchDialog.show();
            }
        }
    });

    const searchLabel = t('search') || 'Search';

    return (
        <>
            {searchDialog.open && <SearchModal open={searchDialog.open} onClose={searchDialog.hide} />}
            <Tooltip placement={'bottom'} content={searchLabel}>
                <button
                    type={'button'}
                    aria-label={searchLabel}
                    className={cn('navigation-link', className)}
                    onClick={searchDialog.show}
                >
                    <Icon icon={Search} />
                </button>
            </Tooltip>
        </>
    );
}
