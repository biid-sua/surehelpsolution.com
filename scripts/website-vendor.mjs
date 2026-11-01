// Copies the public website's libraries from node_modules into public/vendor, so the site loads
// them from our own server instead of outside CDNs. Run `npm run website:vendor` after changing
// one of their versions in package.json, and commit public/vendor.
import { cpSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';

const out = 'public/vendor';
const copy = (from, to) => {
    mkdirSync(`${out}/${to}`.replace(/\/[^/]+$/, ''), { recursive: true });
    cpSync(`node_modules/${from}`, `${out}/${to}`, { recursive: true });
};
rmSync(out, { recursive: true, force: true });

copy('bootstrap/dist/css/bootstrap.min.css', 'bootstrap/bootstrap.min.css');
copy('bootstrap/dist/js/bootstrap.bundle.min.js', 'bootstrap/bootstrap.bundle.min.js');
copy('bootstrap/LICENSE', 'bootstrap/LICENSE');
copy('aos/dist/aos.css', 'aos/aos.css');
copy('aos/dist/aos.js', 'aos/aos.js');
copy('aos/LICENSE', 'aos/LICENSE');
copy('@fortawesome/fontawesome-free/css/all.min.css', 'fontawesome/css/all.min.css');
copy('@fortawesome/fontawesome-free/webfonts', 'fontawesome/webfonts');
copy('@fortawesome/fontawesome-free/LICENSE.txt', 'fontawesome/LICENSE.txt');

// Roboto in the weights the site uses, Latin letters only (as Google Fonts served them).
const faces = [];
for (const weight of [300, 400, 500, 700]) {
    for (const [subset, range] of [
        ['latin', 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
        ['latin-ext', 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
    ]) {
        const file = `roboto-${subset}-${weight}-normal.woff2`;
        copy(`@fontsource/roboto/files/${file}`, `roboto/${file}`);
        faces.push(`@font-face{font-family:'Roboto';font-style:normal;font-display:swap;font-weight:${weight};src:url(./${file}) format('woff2');unicode-range:${range}}`);
    }
}
writeFileSync(`${out}/roboto/roboto.css`, faces.join('\n') + '\n');
copy('@fontsource/roboto/LICENSE', 'roboto/LICENSE');

console.log('Copied the website libraries into public/vendor.');
