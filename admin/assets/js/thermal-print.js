/**
 * thermal-print.js
 * Prints HATCH order receipts to cheap "cat printer" style mini Bluetooth
 * photo/sticker printers (LIOTOG, GB01, GB02, GT01, MX05/06/10, and the many
 * rebrands built on the same chipset).
 *
 * IMPORTANT: these are NOT standard ESC/POS receipt printers. They only
 * understand a proprietary raster/image protocol (reverse-engineered by the
 * hobbyist community, e.g. https://werwolv.net/blog/cat_printer and
 * https://github.com/rbaron/catprinter). There is no text-printing command —
 * everything, including the receipt text below, is rendered to a bitmap on
 * an offscreen <canvas> and sent to the printer as an image.
 *
 * Works in Chrome/Edge on Android and desktop (Web Bluetooth is not
 * available in Safari/iOS or Firefox). Requires HTTPS (or localhost).
 *
 * Usage:  HatchPrinter.printOrder(orderId)
 */
(function (global) {
    'use strict';

    // Print head resolution shared by nearly all of these clone printers,
    // regardless of the paper width printed on the box.
    const PRINTER_WIDTH_PX = 384;

    // Receipt text layout — chosen so ~32 monospace characters fit the width.
    const CHARS_PER_LINE = 32;
    const FONT_SIZE = 20;
    const FONT_FAMILY = "'Courier New', Courier, monospace";
    const LINE_HEIGHT = 26;
    const MARGIN_TOP = 16;
    const MARGIN_BOTTOM = 16;

    // BLE service the printer's write/notify characteristics live under.
    const SERVICE_UUID = '0000af30-0000-1000-8000-00805f9b34fb';
    const WRITE_CHAR_UUID = '0000ae01-0000-1000-8000-00805f9b34fb';
    const NOTIFY_CHAR_UUID = '0000ae02-0000-1000-8000-00805f9b34fb';
    // A few other UUIDs seen on rebrands, tried as a fallback if the above
    // isn't found on this particular unit.
    const FALLBACK_SERVICES = [
        '000018f0-0000-1000-8000-00805f9b34fb',
        '49535343-fe7d-4ae5-8fa9-9fafd205e455'
    ];

    // ── CRC8 table used by every "cat printer" clone (extracted from the
    // official iPrint app by the reverse-engineering community) ──────────
    const CRC8_TABLE = [
        0x00, 0x07, 0x0e, 0x09, 0x1c, 0x1b, 0x12, 0x15, 0x38, 0x3f, 0x36, 0x31, 0x24, 0x23, 0x2a, 0x2d,
        0x70, 0x77, 0x7e, 0x79, 0x6c, 0x6b, 0x62, 0x65, 0x48, 0x4f, 0x46, 0x41, 0x54, 0x53, 0x5a, 0x5d,
        0xe0, 0xe7, 0xee, 0xe9, 0xfc, 0xfb, 0xf2, 0xf5, 0xd8, 0xdf, 0xd6, 0xd1, 0xc4, 0xc3, 0xca, 0xcd,
        0x90, 0x97, 0x9e, 0x99, 0x8c, 0x8b, 0x82, 0x85, 0xa8, 0xaf, 0xa6, 0xa1, 0xb4, 0xb3, 0xba, 0xbd,
        0xc7, 0xc0, 0xc9, 0xce, 0xdb, 0xdc, 0xd5, 0xd2, 0xff, 0xf8, 0xf1, 0xf6, 0xe3, 0xe4, 0xed, 0xea,
        0xb7, 0xb0, 0xb9, 0xbe, 0xab, 0xac, 0xa5, 0xa2, 0x8f, 0x88, 0x81, 0x86, 0x93, 0x94, 0x9d, 0x9a,
        0x27, 0x20, 0x29, 0x2e, 0x3b, 0x3c, 0x35, 0x32, 0x1f, 0x18, 0x11, 0x16, 0x03, 0x04, 0x0d, 0x0a,
        0x57, 0x50, 0x59, 0x5e, 0x4b, 0x4c, 0x45, 0x42, 0x6f, 0x68, 0x61, 0x66, 0x73, 0x74, 0x7d, 0x7a,
        0x89, 0x8e, 0x87, 0x80, 0x95, 0x92, 0x9b, 0x9c, 0xb1, 0xb6, 0xbf, 0xb8, 0xad, 0xaa, 0xa3, 0xa4,
        0xf9, 0xfe, 0xf7, 0xf0, 0xe5, 0xe2, 0xeb, 0xec, 0xc1, 0xc6, 0xcf, 0xc8, 0xdd, 0xda, 0xd3, 0xd4,
        0x69, 0x6e, 0x67, 0x60, 0x75, 0x72, 0x7b, 0x7c, 0x51, 0x56, 0x5f, 0x58, 0x4d, 0x4a, 0x43, 0x44,
        0x19, 0x1e, 0x17, 0x10, 0x05, 0x02, 0x0b, 0x0c, 0x21, 0x26, 0x2f, 0x28, 0x3d, 0x3a, 0x33, 0x34,
        0x4e, 0x49, 0x40, 0x47, 0x52, 0x55, 0x5c, 0x5b, 0x76, 0x71, 0x78, 0x7f, 0x6a, 0x6d, 0x64, 0x63,
        0x3e, 0x39, 0x30, 0x37, 0x22, 0x25, 0x2c, 0x2b, 0x06, 0x01, 0x08, 0x0f, 0x1a, 0x1d, 0x14, 0x13,
        0xae, 0xa9, 0xa0, 0xa7, 0xb2, 0xb5, 0xbc, 0xbb, 0x96, 0x91, 0x98, 0x9f, 0x8a, 0x8d, 0x84, 0x83,
        0xde, 0xd9, 0xd0, 0xd7, 0xc2, 0xc5, 0xcc, 0xcb, 0xe6, 0xe1, 0xe8, 0xef, 0xfa, 0xfd, 0xf4, 0xf3
    ];

    function crc8(bytesArr) {
        let crc = 0;
        for (const b of bytesArr) crc = CRC8_TABLE[(crc ^ b) & 0xff];
        return crc & 0xff;
    }

    // ── Command IDs (the "51 78" cat-printer protocol) ─────────────────
    const CMD = {
        RETRACT_PAPER: 0xa0,
        FEED_PAPER: 0xa1,
        DRAW_BITMAP: 0xa2,
        GET_DEV_STATE: 0xa3,
        SET_QUALITY: 0xa4,
        CONTROL_LATTICE: 0xa6,
        OTHER_FEED_PAPER: 0xbd,
        DRAWING_MODE: 0xbe,
        SET_ENERGY: 0xaf
    };
    const PRINT_LATTICE = [0xaa, 0x55, 0x17, 0x38, 0x44, 0x5f, 0x5f, 0x5f, 0x44, 0x38, 0x2c];
    const FINISH_LATTICE = [0xaa, 0x55, 0x17, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x17];
    const IMG_PRINT_SPEED = [0x19];
    const BLANK_SPEED = [0x05];
    const ENERGY = 0x2ee0; // moderate/default contrast used by the official app
    const TRAILING_FEED_STEPS = 120; // blank feed so the receipt clears the mouth

    function formatMessage(command, data) {
        data = data || [];
        const header = [0x51, 0x78, command, 0x00, data.length, 0x00];
        return new Uint8Array([...header, ...data, crc8(data), 0xff]);
    }

    function le16(n) {
        return [n & 0xff, (n >> 8) & 0xff];
    }

    function concatBytes(chunks) {
        const total = chunks.reduce((n, c) => n + c.length, 0);
        const out = new Uint8Array(total);
        let offset = 0;
        for (const c of chunks) { out.set(c, offset); offset += c.length; }
        return out;
    }

    // ── Receipt text layout helpers (same visual layout as the printed
    // HATCH transaction template, now rendered to pixels instead of ASCII) ─
    function padRight(str, len) { str = String(str).slice(0, len); return str + ' '.repeat(Math.max(0, len - str.length)); }
    function center(str) { str = String(str).slice(0, CHARS_PER_LINE); const pad = Math.max(0, Math.floor((CHARS_PER_LINE - str.length) / 2)); return ' '.repeat(pad) + str; }
    function twoCol(left, right) {
        left = left || ''; right = right || '';
        const space = CHARS_PER_LINE - left.length - right.length;
        if (space < 1) { left = left.slice(0, Math.max(0, CHARS_PER_LINE - right.length - 1)); return left + ' ' + right; }
        return left + ' '.repeat(space) + right;
    }
    function ruleLine(ch) { return (ch || '-').repeat(CHARS_PER_LINE); }
    function money(n) { return 'P' + Number(n || 0).toFixed(2); }
    function wrapText(str, width) {
        str = String(str || '');
        const out = [];
        while (str.length > width) {
            let cut = str.lastIndexOf(' ', width);
            if (cut <= 0) cut = width;
            out.push(str.slice(0, cut).trim());
            str = str.slice(cut).trim();
        }
        if (str) out.push(str);
        return out.length ? out : [''];
    }

    function buildReceiptLines(data) {
        // Each entry: { text, bold, center }
        const L = [];
        const add = (text, opts) => L.push(Object.assign({ text: text, bold: false, center: false }, opts || {}));

        add('HATCH', { bold: true, center: true });
        add("Hiney's Agricultural Trading", { center: true });
        add('& Chicken Hub', { center: true });
        add(ruleLine('='));
        add('TRANSACTION / ORDER', { center: true });
        add(ruleLine('='));
        add('Txn No.: ' + data.transaction_no);
        add('Date: ' + data.date);
        add('');
        add('Customer: ' + data.customer_name);
        if (data.customer_contact) add('Contact:  ' + data.customer_contact);
        if (data.delivery_address) {
            add('Address:');
            wrapText(data.delivery_address, CHARS_PER_LINE).forEach(l => add(l));
        }
        add('');
        add('Payment:  ' + data.payment_method + '  (' + data.payment_status + ')');
        add('Status:   ' + data.order_status);
        add(ruleLine('-'));

        (data.items || []).forEach((it) => {
            wrapText(it.name, CHARS_PER_LINE).forEach(l => add(l));
            add(twoCol('  ' + it.qty + ' x ' + money(it.unit_price), money(it.subtotal)));
        });
        add(ruleLine('-'));

        add(twoCol('Subtotal:', money(data.items_subtotal)));
        add(twoCol('Delivery Fee:', money(data.delivery_fee)));
        if (data.discount) add(twoCol('Discount:', '-' + money(data.discount)));
        add(ruleLine('-'));
        add(twoCol('TOTAL:', money(data.total)), true);
        L[L.length - 1].bold = true;
        add(ruleLine('='));

        if (data.paid_at) {
            add('Paid At: ' + data.paid_at);
            add('');
        }

        add('Thank you for your order!', { center: true });
        add('');
        add('');

        return L;
    }

    // ── Render receipt text to a 1-bit bitmap via an offscreen canvas ──
    function renderReceiptBitmap(data) {
        const lines = buildReceiptLines(data);
        const height = MARGIN_TOP + MARGIN_BOTTOM + lines.length * LINE_HEIGHT;

        const canvas = document.createElement('canvas');
        canvas.width = PRINTER_WIDTH_PX;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000';
        ctx.textBaseline = 'top';

        let y = MARGIN_TOP;
        for (const line of lines) {
            ctx.font = (line.bold ? 'bold ' : '') + FONT_SIZE + 'px ' + FONT_FAMILY;
            const x = line.center ? centerX(ctx, line.text) : 6;
            ctx.fillText(line.text, x, y);
            y += LINE_HEIGHT;
        }

        return canvas;
    }

    function centerX(ctx, text) {
        const w = ctx.measureText(text).width;
        return Math.max(0, Math.round((PRINTER_WIDTH_PX - w) / 2));
    }

    // Pack a canvas into per-row bit arrays for DrawBitmap, matching the
    // exact bit order the printer firmware expects (verified against the
    // reference reverse-engineered implementations).
    function canvasToRows(canvas) {
        const ctx = canvas.getContext('2d');
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const rows = [];
        const bytesPerRow = canvas.width / 8;

        for (let y = 0; y < canvas.height; y++) {
            const row = new Uint8Array(bytesPerRow);
            for (let x = 0; x < canvas.width; x++) {
                const idx = (y * canvas.width + x) * 4;
                const r = img.data[idx], g = img.data[idx + 1], b = img.data[idx + 2];
                const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
                const isBlack = luminance < 140;
                const byteIdx = x >> 3;
                row[byteIdx] >>= 1;
                if (isBlack) row[byteIdx] |= 0x80;
            }
            rows.push(row);
        }
        return rows;
    }

    function buildPrintJob(data) {
        const canvas = renderReceiptBitmap(data);
        const rows = canvasToRows(canvas);

        const chunks = [];
        chunks.push(formatMessage(CMD.GET_DEV_STATE, [0x00]));
        chunks.push(formatMessage(CMD.SET_QUALITY, [0x33]));
        chunks.push(formatMessage(CMD.CONTROL_LATTICE, PRINT_LATTICE));
        chunks.push(formatMessage(CMD.SET_ENERGY, le16(ENERGY)));
        chunks.push(formatMessage(CMD.DRAWING_MODE, [0]));
        chunks.push(formatMessage(CMD.OTHER_FEED_PAPER, IMG_PRINT_SPEED));
        for (const row of rows) {
            chunks.push(formatMessage(CMD.DRAW_BITMAP, Array.from(row)));
        }
        chunks.push(formatMessage(CMD.OTHER_FEED_PAPER, BLANK_SPEED));
        chunks.push(formatMessage(CMD.FEED_PAPER, le16(TRAILING_FEED_STEPS)));
        chunks.push(formatMessage(CMD.CONTROL_LATTICE, FINISH_LATTICE));

        return concatBytes(chunks);
    }

    // ── Bluetooth connection (cached for the session) ──────────────────
    let _conn = null; // { device, characteristic }

    async function connectPrinter() {
        if (_conn && _conn.device.gatt.connected) return _conn;

        if (!navigator.bluetooth) {
            throw new Error('Web Bluetooth is not available in this browser. Use Chrome on Android or desktop.');
        }

        const device = await navigator.bluetooth.requestDevice({
            acceptAllDevices: true,
            optionalServices: [SERVICE_UUID, ...FALLBACK_SERVICES]
        });

        const server = await device.gatt.connect();

        let characteristic = null;
        // Try the known cat-printer characteristic first.
        try {
            const service = await server.getPrimaryService(SERVICE_UUID);
            characteristic = await service.getCharacteristic(WRITE_CHAR_UUID);
        } catch (e) {
            // Fall back to scanning whatever services/characteristics are exposed.
            const services = await server.getPrimaryServices();
            for (const service of services) {
                const chars = await service.getCharacteristics();
                const writable = chars.find(c => c.properties.write || c.properties.writeWithoutResponse);
                if (writable) { characteristic = writable; break; }
            }
        }

        if (!characteristic) {
            throw new Error('Connected, but no writable characteristic was found on this printer.');
        }

        device.addEventListener('gattserverdisconnected', () => { _conn = null; });
        _conn = { device, characteristic };
        return _conn;
    }

    async function writeInChunks(characteristic, data) {
        const CHUNK = 100; // matches the packet size used by the official app
        const useWithoutResponse = characteristic.properties.writeWithoutResponse;
        for (let i = 0; i < data.length; i += CHUNK) {
            const slice = data.slice(i, i + CHUNK);
            if (useWithoutResponse) {
                await characteristic.writeValueWithoutResponse(slice);
            } else {
                await characteristic.writeValue(slice);
            }
            // Sending too fast jams these printers' small buffer — a short
            // pause between packets keeps it happy.
            await new Promise(r => setTimeout(r, 10));
        }
    }

    // ── Public API ───────────────────────────────────────────────────
    async function printOrder(orderId) {
        const res = await fetch('order_receipt_data.php?id=' + encodeURIComponent(orderId));
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || 'Could not load order data for printing.');
        }
        const data = await res.json();
        const job = buildPrintJob(data);
        const { characteristic } = await connectPrinter();
        await writeInChunks(characteristic, job);
    }

    global.HatchPrinter = { printOrder };
})(window);