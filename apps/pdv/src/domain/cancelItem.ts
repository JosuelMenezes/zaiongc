import { enqueue } from '../core/outbox'
import { getDB } from '../core/db'

export async function cancelLocalItem(orderClientUid: string, itemClientUid: string, reason: string) {
  const db = await getDB()

  // Marca localmente como canceled (UX imediata)
  const item = await db.get('order_items', itemClientUid)
  if (item) {
    item.status = 'canceled'
    item.cancel_reason = reason
    item.canceled_at = new Date().toISOString()
    item.updated_at = new Date().toISOString()
    await db.put('order_items', item)
  }

  // Se o item ainda não sincronizou, aqui você pode optar por remover de vez
  // (MVP: apenas marca local e não chama API)
  if (!item?.server_id) return

  const order = await db.get('orders', orderClientUid)
  if (!order?.server_id) return

  await enqueue(
    'order.item.cancel',
    {
      order_server_id: order.server_id,
      item_server_id: item.server_id,
      reason,
    },
    [
      `order:${orderClientUid}:server_id`,
    ]
  )
}
