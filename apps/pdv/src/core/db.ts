import { openDB, type DBSchema, type IDBPDatabase } from 'idb';
import type { MetaRecord, OrderLocal, OrderItemLocal, PaymentLocal, OutboxCommand } from './schema';

interface PdvDB extends DBSchema {
  meta: {
    key: string;
    value: MetaRecord;
  };
  orders: {
    key: string; // client_uid
    value: OrderLocal;
    indexes: { 'by_server_id': number };
  };
  order_items: {
    key: string; // client_uid
    value: OrderItemLocal;
    indexes: { 'by_order_client_uid': string; 'by_server_id': number };
  };
  payments: {
    key: string; // client_uid
    value: PaymentLocal;
    indexes: { 'by_order_client_uid': string; 'by_server_id': number };
  };
  outbox: {
    key: string; // id
    value: OutboxCommand;
    indexes: { 'by_status': string; 'by_next_retry_at': string };
  };

  tables: {
    key: number; // server id
    value: {
      id: number;
      label: string; // nome exibido
      status: 'free' | 'occupied';
      updated_at: string;
      raw?: any; // opcional: payload original
    };
    indexes: { 'by_status': string; 'by_label': string };
  };
}

let dbPromise: Promise<IDBPDatabase<PdvDB>> | null = null;

export function getDB() {
  if (!dbPromise) {
    dbPromise = openDB<PdvDB>('zaiongc-pdv', 1, {
      upgrade(db: IDBPDatabase<PdvDB>) {
        // meta (sem keyPath)
        if (!db.objectStoreNames.contains('meta')) {
          db.createObjectStore('meta');
        }

                // tables (cache local)
        if (!db.objectStoreNames.contains('tables')) {
          const tables = db.createObjectStore('tables', { keyPath: 'id' });
          tables.createIndex('by_status', 'status');
          tables.createIndex('by_label', 'label');
        }

    

        // orders
        if (!db.objectStoreNames.contains('orders')) {
          const orders = db.createObjectStore('orders', { keyPath: 'client_uid' });
          orders.createIndex('by_server_id', 'server_id');
        }

        // order_items
        if (!db.objectStoreNames.contains('order_items')) {
          const items = db.createObjectStore('order_items', { keyPath: 'client_uid' });
          items.createIndex('by_order_client_uid', 'order_client_uid');
          items.createIndex('by_server_id', 'server_id');
        }

        // payments
        if (!db.objectStoreNames.contains('payments')) {
          const payments = db.createObjectStore('payments', { keyPath: 'client_uid' });
          payments.createIndex('by_order_client_uid', 'order_client_uid');
          payments.createIndex('by_server_id', 'server_id');
        }

        // outbox
        if (!db.objectStoreNames.contains('outbox')) {
          const outbox = db.createObjectStore('outbox', { keyPath: 'id' });
          outbox.createIndex('by_status', 'status');
          outbox.createIndex('by_next_retry_at', 'next_retry_at');
          
        }
      },
    });
  }

  return dbPromise;
}
