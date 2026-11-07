const fs = require('fs');

// Support both 'playwright' and local 'playwright-core'
let pw;
try {
    pw = require('playwright');
} catch (_) {
    try {
        pw = require('playwright-core');
    } catch (_) {
        pw = require('C:/Users/ADMIN/.gemini/antigravity/brain/68dd1c54-8199-40a0-bac5-e650bf1ef1de/scratch/node_modules/playwright-core');
    }
}

// 0. Ensure valid PHP session for authentication
const sessionFilePath = 'C:\\xampp\\tmp\\sess_antigravitytestsession';
const sessionData = 'user_id|i:1;username|s:5:"admin";is_admin|i:1;';
fs.writeFileSync(sessionFilePath, sessionData, 'utf8');

(async () => {
    let browser;
    try {
        console.log("=== MEMULAI VERIFIKASI PLAYWRIGHT UI ===");
        
        // 1. Membuka halaman web localhost:8080/hvac_monitoring/index.php
        browser = await pw.chromium.launch({
            executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            headless: true
        });

        const context = await browser.newContext({
            viewport: { width: 1280, height: 800 }
        });

        await context.addCookies([{
            name: 'PHPSESSID',
            value: 'antigravitytestsession',
            domain: 'localhost',
            path: '/'
        }]);

        const page = await context.newPage();
        console.log("1. Membuka http://localhost:8080/hvac_monitoring/index.php...");
        await page.goto('http://localhost:8080/hvac_monitoring/index.php', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // 2. Mengeksekusi JS di browser untuk memanipulasi state: mensimulasikan alarm aktif (unackCount > 0)
        console.log("2. Memanipulasi state: mensimulasikan alarm aktif (unackCount > 0)...");
        await page.evaluate(() => {
            activeAlarmTriggerKeys = new Set(["test:alarm:1"]);
            acknowledgedAlarmKeys = new Set();
            updateGlobalAlarmWidget();
        });
        await page.waitForTimeout(500);

        // 3. Memeriksa apakah elemen button[data-equipment="ALARM"] sekarang memiliki class tab-blinking
        const hasTabBlinking = await page.$eval('button[data-equipment="ALARM"]', el => el.classList.contains('tab-blinking'));
        const tabClassName = await page.$eval('button[data-equipment="ALARM"]', el => el.className);
        console.log(`3. Status class tab-blinking pada tab Alarm: ${hasTabBlinking ? "ADA (BERHASIL)" : "TIDAK ADA (GAGAL)"} (className: "${tabClassName}")`);

        // 4. Mengekstrak computed style animation dan background-color dari elemen tab
        const styles = await page.evaluate(async () => {
            const el = document.querySelector('button[data-equipment="ALARM"]');
            const comp0 = window.getComputedStyle(el);
            const anim = comp0.animation || comp0.animationName;
            const bg0 = comp0.backgroundColor || comp0.background;
            
            // Tunggu 500ms untuk menangkap frame animasi berikutnya
            await new Promise(r => setTimeout(r, 500));
            const comp500 = window.getComputedStyle(el);
            const bg500 = comp500.backgroundColor || comp500.background;

            return {
                animation: anim,
                backgroundColorT0: bg0,
                backgroundColorT500: bg500
            };
        });
        console.log("4. Computed Style Elemen Tab Alarm:");
        console.log(`   - Animation: ${styles.animation}`);
        console.log(`   - Background Color (t=0ms): ${styles.backgroundColorT0}`);
        console.log(`   - Background Color (t=500ms): ${styles.backgroundColorT500}`);

        // 5. Memeriksa apakah document.title tetap "HVAC Monitoring" dan tidak berubah menjadi "(!)"
        const currentTitle = await page.title();
        const titleContainsExclamation = currentTitle.includes("(!)");
        const titleIsExact = (currentTitle === "HVAC Monitoring");
        console.log(`5. Judul Dokumen (document.title): "${currentTitle}"`);
        console.log(`   - Tidak mengandung "(!)": ${!titleContainsExclamation ? "YA (BERHASIL)" : "TIDAK (GAGAL)"}`);
        console.log(`   - Persis "HVAC Monitoring": ${titleIsExact ? "YA (BERHASIL)" : "TIDAK (GAGAL)"}`);

        // Verifikasi kondisi keseluruhan
        const allPassed = hasTabBlinking && !titleContainsExclamation && titleIsExact;
        console.log("\n=== HASIL AKHIR VERIFIKASI ===");
        console.log(allPassed ? ">> SEMUA PENGUJIAN LOLOS DENGAN SUKSES <<" : ">> ADA PENGUJIAN YANG GAGAL <<");

    } catch (err) {
        console.error("Terjadi kesalahan:", err);
    } finally {
        if (browser) {
            await browser.close();
        }
        // Bersihkan file session uji
        try {
            if (fs.existsSync(sessionFilePath)) {
                fs.unlinkSync(sessionFilePath);
            }
        } catch (_) {}
    }
})();
