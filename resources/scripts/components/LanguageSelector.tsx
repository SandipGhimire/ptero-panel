import { Globe, Check } from 'lucide-react';
import DropdownMenu from '@/components/elements/dropdown/DropdownMenu';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Icon from '@/components/elements/Icon';
import { useLanguage } from '@/hooks/useLanguage';
import { cn } from '@/lib/cn';

interface Props {
    className?: string;
}

export default function LanguageSelector({ className }: Props) {
    const { currentLanguage, languages, changeLanguage } = useLanguage();

    const options = Object.entries(languages);

    // If there is only 1 or 0 languages available, don't clutter the header
    if (options.length <= 1) {
        return null;
    }

    const trigger = (
        <Tooltip placement={'bottom'} content={'Select Language'}>
            <button
                type={'button'}
                aria-label={'Select Language'}
                className={cn('flex items-center justify-center outline-hidden', className)}
            >
                <Icon icon={Globe} className={'h-5 w-5'} />
            </button>
        </Tooltip>
    );

    return (
        <DropdownMenu triggerContent={trigger} className={'w-44'}>
            {options.map(([code, name]) => {
                const isSelected = currentLanguage === code;
                return (
                    <DropdownMenu.Item
                        key={code}
                        onClick={() => void changeLanguage(code)}
                        className={cn('flex items-center justify-between', isSelected && 'font-semibold text-primary')}
                    >
                        <span>{name}</span>
                        {isSelected && <Icon icon={Check} className={'h-4 w-4 ml-2 text-primary'} />}
                    </DropdownMenu.Item>
                );
            })}
        </DropdownMenu>
    );
}
