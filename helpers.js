/**
 * EventMS Load Test Suite - Helpers
 */

class CookieJar {
    constructor() {
        this.cookies = new Map();
    }

    parse(res) {
        let setCookies = [];
        if (typeof res.headers.getSetCookie === 'function') {
            setCookies = res.headers.getSetCookie();
        } else {
            const h = res.headers.get('set-cookie');
            if (h) setCookies = [h];
        }
        for (const str of setCookies) {
            const parts = str.split(';')[0].split('=');
            if (parts.length >= 2) {
                this.cookies.set(parts[0].trim(), parts.slice(1).join('=').trim());
            }
        }
    }

    header() {
        return Array.from(this.cookies.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
    }
}

function calculateStats(latencies) {
    if (!latencies || latencies.length === 0) {
        return { count: 0, avg: 0, median: 0, p95: 0, max: 0 };
    }
    const sorted = [...latencies].sort((a, b) => a - b);
    const sum = sorted.reduce((acc, v) => acc + v, 0);
    const avg = sum / sorted.length;
    const median = sorted[Math.floor(sorted.length * 0.5)];
    const p95 = sorted[Math.min(Math.floor(sorted.length * 0.95), sorted.length - 1)];
    const max = sorted[sorted.length - 1];

    return {
        count: sorted.length,
        avg: Math.round(avg),
        median: Math.round(median),
        p95: Math.round(p95),
        max: Math.round(max)
    };
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

module.exports = {
    CookieJar,
    calculateStats,
    sleep
};
