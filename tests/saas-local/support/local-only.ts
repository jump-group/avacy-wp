import net from 'node:net';
import { appendFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Blocco del runner Node: i test parlano solo con host locali.
 * Ogni socket TCP verso un host non locale viene rifiutato prima di partire: per nome, o per IP
 * quando il client risolve il nome da se' (le API di Playwright). Vale per tutto il processo.
 */

export const LOG_DIR = resolve(__dirname, '../guard-log');
// loopback anche come IPv4 mappato su IPv6 (::ffff:127.0.0.1): e' l'IP risolto che Playwright passa al socket
const LOCAL = /^(localhost|(::ffff:)?127\.0\.0\.1|::1|\[::1\]|host\.docker\.internal|apiavacy\.test|avacy\.test|([a-z0-9-]+\.)+avacy\.test)$/i;

export const isLocalHost = (host: string) => LOCAL.test(host.replace(/\.$/, ''));

export function assertLocalUrl(url: string, what: string): string {
  const u = new URL(url);
  if (!/^https?:$/.test(u.protocol) || !isLocalHost(u.hostname)) {
    throw new Error(`${what} non è locale (${url}): prova fermata`);
  }
  return url;
}

// la probe del test di sicurezza marca le sue righe: il controllo finale le distingue dai tentativi veri
let probing = false;
export async function asProbe<T>(fn: () => Promise<T>): Promise<T> {
  probing = true;
  try {
    return await fn();
  } finally {
    probing = false;
  }
}

function targetHost(args: unknown[]): string | null {
  // net.connect passa un array normalizzato [options, cb]; socket.connect accetta anche (options) o (port, host)
  const first = Array.isArray(args[0]) ? args[0][0] : args[0];
  if (first && typeof first === 'object') {
    const o = first as { path?: string; host?: string; hostname?: string };
    if (o.path) return null; // socket unix: non e' rete
    return o.host ?? o.hostname ?? 'localhost';
  }
  if (typeof first === 'string' && Number.isNaN(Number(first))) return null; // percorso unix
  return typeof args[1] === 'string' ? args[1] : 'localhost';
}

const proto = net.Socket.prototype as net.Socket & { __avacyLocalOnly?: boolean };
if (!proto.__avacyLocalOnly) {
  const original = proto.connect;
  proto.connect = function (this: net.Socket, ...args: unknown[]) {
    const host = targetHost(args);
    if (host === null) return (original as (...a: unknown[]) => net.Socket).apply(this, args);
    const allowed = isLocalHost(host);
    mkdirSync(LOG_DIR, { recursive: true });
    appendFileSync(resolve(LOG_DIR, 'node-hosts.log'), `${new Date().toISOString()}\t${allowed ? 'ALLOW' : 'BLOCK'}\t${host}\t${probing ? 'probe' : 'test'}\n`);
    if (!allowed) throw new Error(`avacy_node_guard: host non locale bloccato: ${host}`);
    return (original as (...a: unknown[]) => net.Socket).apply(this, args);
  } as typeof proto.connect;
  proto.__avacyLocalOnly = true;
}
