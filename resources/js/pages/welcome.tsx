import { mergeContent } from '@/components/landing/content';
import { LandingPage } from '@/components/landing/landing-page';

/**
 * The public landing page on `/`. Its texts, section order, video and screenshots come from the
 * version a platform admin published in the visual editor (Platform > Landing page), merged over
 * the built-in defaults.
 */
export default function Welcome({ content }: { content?: unknown }) {
    return <LandingPage content={mergeContent(content ?? null)} />;
}
