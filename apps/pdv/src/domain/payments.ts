import { ulid } from 'ulid'
import { getDB } from '../core/db'
import { enqueue } from '../core/outbox'
import type { PaymentLocal } from '../core/schema'

type PaymentMethod = PaymentLocal['method']

export async function createPendingPayment(
  orderClientUid: string,
  method: PaymentMethod,
  amount: number
) {
  const db = await getDB()

  // dedupe: já existe pagamento pending igual?
  const all = await db.getAllFromIndex('payments', 'by_order_client_uid', orderClientUid)

  const exists = all.find((p: any) =>
    p.status === 'pending' &&
    p.method === method &&
    Number(p.amount) === Number(amount)
  )

  if (exists) return exists.client_uid as string

  const paymentUid = ulid()

  const payment: PaymentLocal = {
    client_uid: paymentUid,
    order_client_uid: orderClientUid,
    server_id: null,
    method,
    amount,
    status: 'pending',
    created_at: new Date().toISOString(),
  }

  await db.put('payments', payment)

  await enqueue(
    'payment.add',
    { order_client_uid: orderClientUid, method, amount },
    [`order:${orderClientUid}:server_id`],
    { payment_client_uid: paymentUid }
  )

  return paymentUid
}
