#!/usr/bin/env node
/**
 * tools/lint_inline.js
 *
 * Extracts inline JavaScript from index.php with exact 1:1 line preservation,
 * and runs ESLint 'no-undef' check including URLSearchParams and TextEncoder globals.
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const cp = require('child_process');

const repoRoot = path.resolve(__dirname, '..');
const targetFile = process.argv[2] ? path.resolve(process.argv[2]) : path.join(repoRoot, 'index.php');

if (!fs.existsSync(targetFile)) {
    console.error(`Target file not found: ${targetFile}`);
    process.exit(1);
}

const content = fs.readFileSync(targetFile, 'utf8');
const scriptStartTag = '<script>';
const scriptEndTag = '</script>';

const scriptStartIdx = content.indexOf(scriptStartTag);
const scriptEndIdx = content.lastIndexOf(scriptEndTag);

if (scriptStartIdx === -1 || scriptEndIdx === -1) {
    console.error('No inline <script> block found in target file.');
    process.exit(1);
}

const beforeScript = content.substring(0, scriptStartIdx + scriptStartTag.length);
const scriptBody = content.substring(scriptStartIdx + scriptStartTag.length, scriptEndIdx);

// Count newlines before script body to pad lines for exact line preservation
const beforeLines = beforeScript.split('\n').length;

// Replace PHP blocks within JS body while strictly preserving line count
const cleanBody = scriptBody.replace(/<\?(=|php)[\s\S]*?\?>/g, (match) => {
    const newlines = match.split('\n').length - 1;
    return '{}' + '\n'.repeat(newlines);
});

const paddedScript = Array(beforeLines).join('// pad\n') + cleanBody;

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'hvac_lint_'));
const tempJsPath = path.join(tmpDir, 'extracted_inline.js');
const configPath = path.join(tmpDir, 'eslint.config.mjs');

const eslintConfig = `export default [
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: "script",
            globals: {
                window: "readonly",
                document: "readonly",
                console: "readonly",
                fetch: "readonly",
                setTimeout: "readonly",
                clearTimeout: "readonly",
                setInterval: "readonly",
                clearInterval: "readonly",
                AbortController: "readonly",
                Date: "readonly",
                Number: "readonly",
                String: "readonly",
                Boolean: "readonly",
                Array: "readonly",
                Object: "readonly",
                Set: "readonly",
                Map: "readonly",
                Promise: "readonly",
                Math: "readonly",
                JSON: "readonly",
                RegExp: "readonly",
                Error: "readonly",
                parseInt: "readonly",
                parseFloat: "readonly",
                isNaN: "readonly",
                isFinite: "readonly",
                encodeURIComponent: "readonly",
                decodeURIComponent: "readonly",
                requestAnimationFrame: "readonly",
                cancelAnimationFrame: "readonly",
                localStorage: "readonly",
                sessionStorage: "readonly",
                Audio: "readonly",
                Event: "readonly",
                CustomEvent: "readonly",
                HTMLElement: "readonly",
                MutationObserver: "readonly",
                IntersectionObserver: "readonly",
                ResizeObserver: "readonly",
                Blob: "readonly",
                URL: "readonly",
                navigator: "readonly",
                location: "readonly",
                history: "readonly",
                alert: "readonly",
                confirm: "readonly",
                prompt: "readonly",
                AudioContext: "readonly",
                webkitAudioContext: "readonly",
                URLSearchParams: "readonly",
                TextEncoder: "readonly"
            }
        },
        rules: {
            "no-undef": "error"
        }
    }
];
`;

try {
    fs.writeFileSync(tempJsPath, paddedScript, 'utf8');
    fs.writeFileSync(configPath, eslintConfig, 'utf8');

    const totalLines = paddedScript.split('\n').length;
    console.log(`Auditing inline script (${totalLines} lines) with ESLint no-undef...`);

    let stdout = '';
    try {
        stdout = cp.execSync(`npx eslint -c "${configPath}" --no-ignore "${tempJsPath}" -f json`, {
            encoding: 'utf8',
            stdio: ['pipe', 'pipe', 'pipe']
        });
    } catch (e) {
        if (e.stdout) {
            stdout = e.stdout.toString();
        } else {
            throw e;
        }
    }

    const report = JSON.parse(stdout);
    const noUndefErrors = (report[0]?.messages || []).filter(m => m.ruleId === 'no-undef');

    if (noUndefErrors.length === 0) {
        console.log(`[PASS] ESLint no-undef check passed with 0 errors across ${totalLines} lines.`);
        process.exit(0);
    } else {
        console.error(`[FAIL] Found ${noUndefErrors.length} undeclared variable(s):`);
        for (const err of noUndefErrors) {
            console.error(`  Line ${err.line}:${err.column} - ${err.message}`);
        }
        process.exit(1);
    }
} catch (err) {
    console.error('Linting execution failed:', err.message);
    process.exit(1);
} finally {
    try {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    } catch (_) {}
}
