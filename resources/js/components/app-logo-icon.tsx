import { SVGAttributes } from 'react';

// Balle de padel (coutures découpées par un masque) et deux cases en diagonale, comme des pixels d'arcade.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 45 42" xmlns="http://www.w3.org/2000/svg">
            <mask id="padelbxl-seams">
                <rect width="40" height="42" fill="white" />
                <path d="M6 10C16 16 16 26 6 32M34 10C24 16 24 26 34 32" stroke="black" strokeWidth="3" fill="none" />
            </mask>
            <g transform="translate(0 4) scale(0.9)">
                <circle cx="20" cy="21" r="18" mask="url(#padelbxl-seams)" />
            </g>
            <rect x="34" y="6" width="5" height="5" />
            <rect x="40" y="0" width="5" height="5" />
        </svg>
    );
}
