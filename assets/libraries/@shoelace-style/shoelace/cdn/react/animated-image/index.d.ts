import Component from '../../components/animated-image/animated-image.component.js';
import { type EventName } from '@lit/react';
import type { SlLoadEvent } from '../../events/events.js';
import type { SlErrorEvent } from '../../events/events.js';
export type { SlLoadEvent } from '../../events/events.js';
export type { SlErrorEvent } from '../../events/events.js';
/**
 * @summary A component for displaying animated GIFs and WEBPs that play and pause on interaction.
 * @documentation https://shoelace.style/components/animated-image
 * @status stable
 * @since 2.0
 *
 * @dependency sl-icon
 *
 * @event sl-load - Emitted when the image loads successfully.
 * @event sl-error - Emitted when the image fails to load.
 *
 * @slot play-icon - Optional play icon to use instead of the default. Works best with `<sl-icon>`.
 * @slot pause-icon - Optional pause icon to use instead of the default. Works best with `<sl-icon>`.
 *
 * @part control-box - The container that surrounds the pause/play icons and provides their background.
 *
 * @cssproperty --control-box-size - The size of the icon box.
 * @cssproperty --icon-size - The size of the play/pause icons.
 */
declare const reactWrapper: import("@lit/react").ReactWebComponent<Component, {
    onSlLoad: EventName<SlLoadEvent>;
    onSlError: EventName<SlErrorEvent>;
}>;
export default reactWrapper;
