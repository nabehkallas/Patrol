import type { SVGAttributes } from 'react';

/** Patrol's mark: a fuel pump with its hose and a drop in the display. Drawn in `currentColor`. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path
                fill="currentColor"
                fillRule="evenodd"
                d="M10 5h12a3 3 0 0 1 3 3v24H7V8a3 3 0 0 1 3-3Zm1.5 4A1.5 1.5 0 0 0 10 10.5v6a1.5 1.5 0 0 0 1.5 1.5h9a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 20.5 9h-9ZM16 20.5c-1.6 2.2-2.6 3.8-2.6 5a2.6 2.6 0 0 0 5.2 0c0-1.2-1-2.8-2.6-5Z"
            />
            <path
                fill="currentColor"
                d="M4 33.5A1.5 1.5 0 0 1 5.5 32h21a1.5 1.5 0 0 1 1.5 1.5V36H4v-2.5Z"
            />
            <path
                fill="none"
                stroke="currentColor"
                strokeWidth="2.4"
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M25 12h3.5a2.5 2.5 0 0 1 2.5 2.5V26a2 2 0 0 0 4 0V15l-3-3.5"
            />
        </svg>
    );
}
