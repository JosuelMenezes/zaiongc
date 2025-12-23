import { getDB } from '../core/db'

export async function removePendingItem(itemClientUid: string) {
  const db = await getDB()

  // 1) remove o item local
  await db.delete('order_items', itemClientUid)

  // 2) remove comandos outbox relacionados a esse item (ainda pending)
  const pending = await db.getAllFromIndex('outbox', 'by_status', 'pending')

  const toDelete = pending.filter(cmd =>
    cmd.type === 'order.item.add' &&
    cmd.meta?.item_client_uid === itemClientUid
  )

  for (const cmd of toDelete) {
    await db.delete('outbox', cmd.id)
  }
}
