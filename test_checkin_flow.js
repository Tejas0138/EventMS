const BASE_URL = 'http://localhost:8080/EventMS_loadtest';

async function testCheckin(identifier) {
    const jar = new Map();
    function parseCookies(res) {
        const setCookie = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie')].filter(Boolean);
        for (const str of setCookie) {
            const parts = str.split(';')[0].split('=');
            if (parts.length >= 2) {
                jar.set(parts[0].trim(), parts.slice(1).join('=').trim());
            }
        }
    }
    function getCookieHeader() {
        return Array.from(jar.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
    }

    const t0 = performance.now();
    const getRes = await fetch(`${BASE_URL}/gate_checkin.php`);
    parseCookies(getRes);
    const html = await getRes.text();
    const loadTime = performance.now() - t0;

    const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i) || html.match(/name='csrf_token'\s+value='([^']+)'/i);
    const csrf = match ? match[1] : '';

    const t1 = performance.now();
    const body = new URLSearchParams({
        identifier: identifier,
        csrf_token: csrf
    });

    const postRes = await fetch(`${BASE_URL}/gate_checkin.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': getCookieHeader(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: body.toString()
    });
    parseCookies(postRes);
    const postTime = performance.now() - t1;

    let json = null;
    try {
        json = await postRes.json();
    } catch (e) {
        // Fallback HTML parse if JSON wasn't returned
    }

    console.log(`Checkin ${identifier}: Status=${postRes.status}, LoadTime=${loadTime.toFixed(1)}ms, PostTime=${postTime.toFixed(1)}ms, Result=`, json);
}

testCheckin('LOADTEST_001');
