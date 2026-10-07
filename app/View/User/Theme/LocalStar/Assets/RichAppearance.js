(function () {
    'use strict';

    // The helpers are pure so color/contrast behavior can be tested without a
    // browser or a checkout request. No merchant HTML or image pixels are read.
    const clamp = (number, low, high) => Math.min(high, Math.max(low, number));
    const parseColor = function (value) {
        value = String(value || '').trim().toLowerCase();
        if (value === 'transparent') return [0, 0, 0, 0];
        // color-mix(in srgb,currentColor …,transparent) in RichHtml's code/
        // table styles is serialized this way by modern Chrome and Safari.
        const srgb = value.match(/^color\(\s*(srgb(?:-linear)?)\s+([\d.e+-]+%?)\s+([\d.e+-]+%?)\s+([\d.e+-]+%?)(?:\s*\/\s*([\d.e+-]+%?))?\s*\)$/);
        if (srgb) {
            const channel = function (token) {
                let number = clamp(parseFloat(token) * (token.endsWith('%') ? .01 : 1), 0, 1);
                if (srgb[1] === 'srgb-linear') number = number <= .0031308 ? number * 12.92 : 1.055 * Math.pow(number, 1 / 2.4) - .055;
                return number * 255;
            };
            const alpha = srgb[5] == null ? 1 : clamp(parseFloat(srgb[5]) * (srgb[5].endsWith('%') ? .01 : 1), 0, 1);
            const result = [channel(srgb[2]), channel(srgb[3]), channel(srgb[4]), alpha];
            return result.every(Number.isFinite) ? result : null;
        }
        const match = value.match(/^rgba?\(\s*([\d.+-]+%?)\s*[, ]\s*([\d.+-]+%?)\s*[, ]\s*([\d.+-]+%?)(?:\s*[,/]\s*([\d.+-]+%?))?\s*\)$/);
        if (!match) return null;
        const channel = token => clamp(parseFloat(token) * (token.endsWith('%') ? 2.55 : 1), 0, 255);
        const alpha = match[4] == null ? 1 : clamp(parseFloat(match[4]) * (match[4].endsWith('%') ? .01 : 1), 0, 1);
        const result = [channel(match[1]), channel(match[2]), channel(match[3]), alpha];
        return result.every(Number.isFinite) ? result : null;
    };
    const composite = (foreground, background) => [0, 1, 2].map(index => foreground[index] * foreground[3] + background[index] * (1 - foreground[3])).concat(1);
    const luminance = color => color.slice(0, 3).reduce(function (total, channel, index) {
        const value = channel / 255;
        return total + (value <= .04045 ? value / 12.92 : Math.pow((value + .055) / 1.055, 2.4)) * [.2126, .7152, .0722][index];
    }, 0);
    const contrast = function (foreground, background) {
        const first = luminance(composite(foreground, background));
        const second = luminance(background);
        return (Math.max(first, second) + .05) / (Math.min(first, second) + .05);
    };
    const toHsl = function (color) {
        const channels = color.slice(0, 3).map(value => value / 255);
        const high = Math.max(...channels), low = Math.min(...channels), delta = high - low;
        const lightness = (high + low) / 2;
        if (delta === 0) return [0, 0, lightness];
        const saturation = delta / (1 - Math.abs(2 * lightness - 1));
        const hue = high === channels[0] ? ((channels[1] - channels[2]) / delta + (channels[1] < channels[2] ? 6 : 0)) / 6
            : high === channels[1] ? ((channels[2] - channels[0]) / delta + 2) / 6 : ((channels[0] - channels[1]) / delta + 4) / 6;
        return [hue, saturation, lightness];
    };
    const fromHsl = function (hue, saturation, lightness, alpha) {
        const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation;
        const sector = hue * 6;
        const second = chroma * (1 - Math.abs(sector % 2 - 1));
        const channels = sector < 1 ? [chroma, second, 0] : sector < 2 ? [second, chroma, 0] : sector < 3 ? [0, chroma, second]
            : sector < 4 ? [0, second, chroma] : sector < 5 ? [second, 0, chroma] : [chroma, 0, second];
        const offset = lightness - chroma / 2;
        return channels.map(channel => (channel + offset) * 255).concat(alpha);
    };
    const adjustContrast = function (foreground, background, minimum = 5.5) {
        if (!foreground || !background || contrast(foreground, background) >= minimum) return null;
        const [hue, saturation, originalLightness] = toHsl(foreground);
        // A small margin survives CSS channel serialization/rounding. Preserve
        // alpha unless that alpha makes the requested contrast unattainable.
        const target = minimum + .05;
        for (const alpha of foreground[3] === 1 ? [1] : [foreground[3], 1]) {
            const candidates = [];
            for (const lighter of [false, true]) {
                const edge = lighter ? 1 : 0;
                if (contrast(fromHsl(hue, saturation, edge, alpha), background) < target) continue;
                let bad = originalLightness, good = edge;
                for (let step = 0; step < 28; step++) {
                    const middle = (bad + good) / 2;
                    if (contrast(fromHsl(hue, saturation, middle, alpha), background) >= target) good = middle;
                    else bad = middle;
                }
                candidates.push({distance: Math.abs(good - originalLightness), color: fromHsl(hue, saturation, good, alpha)});
            }
            if (candidates.length) return candidates.sort((first, second) => first.distance - second.distance)[0].color;
        }
        return null;
    };
    const serialize = color => 'rgba(' + color.slice(0, 3).map(channel => channel.toFixed(3)).join(', ') + ', ' + color[3].toFixed(4) + ')';
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {parseColor, composite, luminance, contrast, toHsl, fromHsl, adjustContrast, serialize};
        return;
    }
    if (window.__storeRichAppearance) {
        window.__storeRichAppearance.init();
        return;
    }

    const selector = '.store-description, .store-rich';
    const excluded = 'script,style,img,picture,svg,video,audio,canvas,iframe,object,math,input,textarea,select,button,form,[contenteditable]';
    const saved = new Map();
    let roots = [], observer = null, pending = 0;
    const restore = function () {
        saved.forEach(function (record, element) {
            if (element.getAttribute('style') === record.appliedStyle) {
                if (record.originalStyle === null) element.removeAttribute('style');
                else element.setAttribute('style', record.originalStyle);
                return;
            }
            record.properties.forEach(function (property) {
                const applied = record.appliedProperties.get(property.name);
                if (applied && (element.style.getPropertyValue(property.name) !== applied.value || element.style.getPropertyPriority(property.name) !== applied.priority)) return;
                if (property.value) element.style.setProperty(property.name, property.value, property.priority);
                else element.style.removeProperty(property.name);
            });
            // Remove only our formerly absent, now empty attribute. Never erase
            // a style another script added while the appearance was active.
            if (!record.hadStyle && element.style.length === 0) element.removeAttribute('style');
        });
        saved.clear();
    };
    const refresh = function () {
        pending = 0;
        restore();
        if (document.documentElement.dataset.storeTheme !== 'dark') return;
        const repair = function () {
            const styles = new Map(), backgrounds = new Map(), changes = [];
            const styleOf = function (element) {
                if (!styles.has(element)) styles.set(element, getComputedStyle(element));
                return styles.get(element);
            };
            const backgroundOf = function (element) {
                if (!element) return {color: [255, 255, 255, 1], uncertain: false, effects: false};
                if (backgrounds.has(element)) return backgrounds.get(element);
                const parent = backgroundOf(element.parentElement);
                const style = styleOf(element), background = parseColor(style.backgroundColor);
                let uncertain = parent.uncertain;
                if (!background) uncertain = true;
                // An opaque child surface hides its ancestor's image/gradient.
                else if (background[3] >= .9999) uncertain = false;
                if (style.backgroundImage && style.backgroundImage !== 'none') uncertain = true;
                const effects = parent.effects || Number(style.opacity || 1) < 1 || (style.mixBlendMode && style.mixBlendMode !== 'normal') || (style.filter && style.filter !== 'none');
                const result = {color: background ? composite(background, parent.color) : parent.color, uncertain, effects};
                backgrounds.set(element, result);
                return result;
            };
            roots.filter(root => root.isConnected).forEach(function (root) {
                [root, ...root.querySelectorAll('*')].forEach(function (element) {
                    if (element.closest(excluded) || element.closest('[hidden]')) return;
                    const hasText = [...element.childNodes].some(node => node.nodeType === 3 && node.textContent.trim() !== '');
                    if (!hasText && element.tagName !== 'LI') return;
                    const style = styleOf(element);
                    if (style.display === 'none' || style.visibility === 'hidden') return;
                    const background = backgroundOf(element);
                    if (background.uncertain || background.effects) return;
                    const fillValue = style.getPropertyValue('-webkit-text-fill-color');
                    const fill = fillValue ? parseColor(fillValue) : null;
                    // Transparent fill usually means intentionally clipped gradient
                    // text. Leave it intact instead of destroying its presentation.
                    if (fill && fill[3] === 0) return;
                    const foreground = fill || parseColor(style.color);
                    if (!foreground || foreground[3] === 0) return;
                    const adjusted = adjustContrast(foreground, background.color);
                    if (adjusted) changes.push({element, color: serialize(adjusted), fill: !!fillValue});
                });
            });
            // Each pass reads all colors before writing. A parent's change can make
            // an inherited child on another surface unreadable, or move a
            // currentColor-derived code background; subsequent passes remeasure it.
            changes.forEach(function (change) {
                const element = change.element;
                const properties = (change.fill ? ['color', '-webkit-text-fill-color'] : ['color']).map(name => ({name, value: element.style.getPropertyValue(name), priority: element.style.getPropertyPriority(name)}));
                if (!saved.has(element)) saved.set(element, {hadStyle: element.hasAttribute('style'), originalStyle: element.getAttribute('style'), properties, appliedProperties: new Map()});
                properties.forEach(property => element.style.setProperty(property.name, change.color, 'important'));
                const record = saved.get(element);
                record.appliedStyle = element.getAttribute('style');
                properties.forEach(property => record.appliedProperties.set(property.name, {value: element.style.getPropertyValue(property.name), priority: element.style.getPropertyPriority(property.name)}));
            });
            return changes.length;
        };
        // Bound work even when a merchant's custom CSS makes foreground and
        // background permanently identical. Never repeatedly mutate the DOM.
        for (let pass = 0; pass < 4; pass++) if (repair() === 0) break;
    };
    const schedule = function () { if (!pending) pending = requestAnimationFrame(refresh); };
    const init = function () {
        if (observer) observer.disconnect();
        restore();
        // Nested rich containers share one traversal/restoration record.
        roots = [...document.querySelectorAll(selector)].filter(root => !root.parentElement || !root.parentElement.closest(selector));
        if (roots.length) {
            observer = new MutationObserver(schedule);
            roots.forEach(root => observer.observe(root, {childList: true, subtree: true, characterData: true}));
        }
        schedule();
    };
    window.__storeRichAppearance = {init};
    document.addEventListener('store:appearancechange', schedule);
    // Also accept a window-targeted CustomEvent from a future shared shell.
    window.addEventListener('store:appearancechange', schedule);
    window.addEventListener('pagehide', function () {
        if (observer) observer.disconnect();
        if (pending) cancelAnimationFrame(pending);
        pending = 0;
        restore();
    });
    window.addEventListener('pageshow', init);
    if (window.jQuery) $(document).off('pjax:end.storeRichAppearance').on('pjax:end.storeRichAppearance', init);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true}); else init();
}());
