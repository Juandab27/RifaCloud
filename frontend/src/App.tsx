import { Route, Routes } from 'react-router-dom'
import AdminRoute from './components/AdminRoute'
import Layout from './components/Layout'
import RequireAuth from './components/RequireAuth'
import CatalogPage from './pages/CatalogPage'
import LoginPage from './pages/LoginPage'
import NotFoundPage from './pages/NotFoundPage'
import OrderDetailPage from './pages/OrderDetailPage'
import OrdersPage from './pages/OrdersPage'
import RaffleDetailPage from './pages/RaffleDetailPage'
import RegisterPage from './pages/RegisterPage'
import AdminRaffleFormPage from './pages/admin/AdminRaffleFormPage'
import AdminRafflesPage from './pages/admin/AdminRafflesPage'
import AdminOrdersPage from './pages/admin/AdminOrdersPage'

export default function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<CatalogPage />} />
        <Route path="raffles/:raffleId" element={<RaffleDetailPage />} />
        <Route path="login" element={<LoginPage />} />
        <Route path="register" element={<RegisterPage />} />

        <Route element={<RequireAuth />}>
          <Route path="orders" element={<OrdersPage />} />
          <Route path="orders/:orderId" element={<OrderDetailPage />} />
        </Route>

        <Route element={<AdminRoute />}>
          <Route path="admin/raffles" element={<AdminRafflesPage />} />
          <Route path="admin/orders" element={<AdminOrdersPage />} />
          <Route path="admin/raffles/new" element={<AdminRaffleFormPage />} />
          <Route path="admin/raffles/:raffleId/edit" element={<AdminRaffleFormPage />} />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
