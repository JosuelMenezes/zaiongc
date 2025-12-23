import { ulid } from 'ulid';
import { getDB } from '../core/db';
import { enqueue } from '../core/outbox';
import type { OrderLocal, OrderItemLocal } from '../core/schema';

function nowIso() { return new Date().toISOString(); }

export async function createLocalOrder(params: {
  type: 'table' | 'counter' | 'delivery';
  table_id?: number | null;     // server table id (cache)
  terminal_id: number;          // server terminal id
}) {
  const db = await getDB();

  const order: OrderLocal = {
    client_uid: ulid(),
    server_id: null,
    type: params.type,
    table_id: params.table_id ?? null,
    terminal_id: params.terminal_id,
    shift_id: null,
    status_local: 'pending',
    subtotal: 0,
    total: 0,
    created_at: nowIso(),
    updated_at: nowIso(),
  };

  await db.put('orders', order);

  // Dependência: shift open para table/counter
  const depends = (order.type === 'table' || order.type === 'counter')
    ? ['meta:shift_id']
    : [];

  await enqueue('order.open', {
    order_client_uid: order.client_uid,
    type: order.type,
    table_id: order.table_id,
    terminal_id: order.terminal_id,
  }, depends);

  return order.client_uid;
}

export async function addLocalItem(order_client_uid: string, item: {
  name: string;
  quantity: number;
  unit_price: number;
  notes?: string | null;
}) {
  const db = await getDB();

  const it: OrderItemLocal = {
    client_uid: ulid(),
    order_client_uid,
    server_id: null,
    name: item.name,
    quantity: item.quantity,
    unit_price: item.unit_price,
    notes: item.notes ?? null,
    status: 'pending',
    total: Math.round(item.quantity * item.unit_price * 100) / 100,
    created_at: nowIso(),
  };

  await db.put('order_items', it);

  // comando depende de order ter server_id
  await enqueue('order.add_item', {
    order_client_uid,
    item_client_uid: it.client_uid,
    name: it.name,
    quantity: it.quantity,
    unit_price: it.unit_price,
    notes: it.notes,
  }, [`order:${order_client_uid}:server_id`]);

  return it.client_uid;
}
