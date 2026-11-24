/**
 * EventMS Load Test Suite - Scenarios Implementation
 */
const { CookieJar, calculateStats, sleep } = require('./helpers');
const { execSync } = require('child_process');

const BASE_URL = 'http://localhost:8080/EventMS_loadtest';
const PHP_PATH = 'C:\\xampp\\php\\php.exe';
const DB_HELPER = 'c:\\xampp\\htdocs\\EventMS\\loadtest\\db_helper.php';

function runDb(action, ...args) {
    const cmd = `"${PHP_PATH}" "${DB_HELPER}" ${action} ${args.join(' ')}`;
    const out = execSync(cmd).toString().trim();
    try {
        return JSON.parse(out);
    } catch (e) {
        return out;
    }
}

// Counter credentials map
const COUNTER_PASSWORDS = {
    'raipur': 'raipur@123',
    'dehradun': 'dehradun@123',
    'gurugram': 'gurugram@123',
    'chennai': 'chennai@123',
    'panchkula': 'panchkula@123',
    'pune1': 'pune1@123',
    'pune2': 'pune2@123',
    'pune3': 'pune3@123',
    'pune4': 'pune4@123',
    'noida': 'noida@123',
    'bengaluru': 'bengaluru@123',
    'bhopal': 'bhopal@123',
    'bhubaneswar': 'bhubaneswar@123',
    'ajmer': 'ajmer@123',
    'ahmedabad': 'ahmedabad@123',
    'patna': 'patna@123',
    'trivandrum': 'trivandrum@123',
    'delhiwest': 'delhiwest@123',
    'delhieast': 'delhieast@123',
    'ranchi': 'ranchi@123'
};

async function loginUser(username, password) {
    const jar = new CookieJar();
    const getRes = await fetch(`${BASE_URL}/login.php`, {
        signal: AbortSignal.timeout(8000)
    });
    jar.parse(getRes);
    const html = await getRes.text();
    const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i) || html.match(/name='csrf_token'\s+value='([^']+)'/i);
    const csrf = match ? match[1] : '';

    const body = new URLSearchParams({
        username,
        password,
        csrf_token: csrf
    });

    const postRes = await fetch(`${BASE_URL}/login.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': jar.header()
        },
        body: body.toString(),
        redirect: 'manual',
        signal: AbortSignal.timeout(8000)
    });
    jar.parse(postRes);

    return {
        username,
        jar,
        cookie: jar.header(),
        status: postRes.status,
        location: postRes.headers.get('location') || ''
    };
}

async function performSingleCheckin(identifier, pauseMs = 0) {
    const jar = new CookieJar();
    const t0 = performance.now();
    let loadRes;
    try {
        loadRes = await fetch(`${BASE_URL}/gate_checkin.php`, {
            headers: { 'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' },
            signal: AbortSignal.timeout(8000)
        });
        jar.parse(loadRes);
    } catch (e) {
        return { error: 'fetch_failed', loadTime: 0, postTime: 0, status: 0, json: null };
    }
    const loadTime = performance.now() - t0;
    const html = await loadRes.text();

    const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i) || html.match(/name='csrf_token'\s+value='([^']+)'/i);
    const csrf = match ? match[1] : '';

    if (pauseMs > 0) {
        await sleep(pauseMs);
    }

    const t1 = performance.now();
    let postRes;
    try {
        postRes = await fetch(`${BASE_URL}/gate_checkin.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Cookie': jar.header(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'
            },
            body: new URLSearchParams({
                identifier,
                csrf_token: csrf
            }).toString(),
            signal: AbortSignal.timeout(8000)
        });
    } catch (e) {
        return { error: 'post_failed', loadTime, postTime: 0, status: 0, json: null };
    }
    const postTime = performance.now() - t1;

    let json = null;
    try {
        json = await postRes.json();
    } catch (e) {
        json = { rawHtml: await postRes.text().catch(() => '') };
    }

    return {
        identifier,
        status: postRes.status,
        loadTime,
        postTime,
        json
    };
}

module.exports = {
    runDb,
    COUNTER_PASSWORDS,
    loginUser,
    performSingleCheckin,
    BASE_URL
};
