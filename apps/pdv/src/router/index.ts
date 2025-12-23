import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import SetupTerminalView from '../views/SetupTerminalView.vue'
import ShiftView from '../views/ShiftView.vue'
import OrderView from '../views/OrderView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/shift' },
    { path: '/login', component: LoginView },
    { path: '/setup-terminal', component: SetupTerminalView },
    { path: '/shift', component: ShiftView },
    { path: '/order/:orderClientUid', component: OrderView },
  ],
})

export default router
