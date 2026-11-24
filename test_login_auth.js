const BASE_URL = 'http://localhost:8080/EventMS_loadtest';

async function testLogin(username, password) {
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

    // 1. GET login.php
    const getRes = await fetch(`${BASE_URL}/login.php`);
    parseCookies(getRes);
    const html = await getRes.text();
    const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i) || html.match(/name='csrf_token'\s+value='([^']+)'/i);
    const csrf = match ? match[1] : '';

    // 2. POST login.php
    const body = new URLSearchParams({
        username: username,
        password: password,
        csrf_token: csrf
    });

    const postRes = await fetch(`${BASE_URL}/login.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': getCookieHeader()
        },
        body: body.toString(),
        redirect: 'manual'
    });
    parseCookies(postRes);

    const loc = postRes.headers.get('location') || '';
    console.log(`Login ${username}: Status=${postRes.status}, Location=${loc}`);
    return { success: loc.includes('admin.php'), jar, cookie: getCookieHeader() };
}

async function run() {
    await testLogin('testadmin', 'testadmin@123');
    await testLogin('raipur', 'raipur@123');
}
run();
