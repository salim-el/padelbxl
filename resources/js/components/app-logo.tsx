import AppLogoIcon from './app-logo-icon';

// Logo officiel sur le menu bleu nuit ; menu replié, seule la balle reste.
export default function AppLogo() {
    return (
        <>
            <img src="/images/logo-fond-sombre.webp" alt="PadelBxl" className="h-10 w-auto group-data-[collapsible=icon]:hidden" />
            <div className="hidden aspect-square size-8 items-center justify-center rounded-md bg-[#C8E64A] group-data-[collapsible=icon]:flex">
                <AppLogoIcon className="size-5 fill-current text-[#0E1B2C]" />
            </div>
        </>
    );
}
