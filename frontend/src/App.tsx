import { Link, Route, Routes } from 'react-router-dom'
import AdminRoute from './components/AdminRoute'
import Layout from './components/Layout'
import CatalogPage from './pages/CatalogPage'
import LoginPage from './pages/LoginPage'
import RaffleDetailPage from './pages/RaffleDetailPage'
import RegisterPage from './pages/RegisterPage'
import AdminRaffleFormPage from './pages/admin/AdminRaffleFormPage'
import AdminRafflesPage from './pages/admin/AdminRafflesPage'

function NotFoundPage() {
  return (
    <section className="card narrow">
      <h1>Página no encontrada</h1>
      <Link to="/">Volver al catálogo</Link>
    </section>
  )
}

export default function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<CatalogPage />} />
        <Route path="raffles/:raffleId" element={<RaffleDetailPage />} />
        <Route path="login" element={<LoginPage />} />
        <Route path="register" element={<RegisterPage />} />

        <Route element={<AdminRoute />}>
          <Route path="admin/raffles" element={<AdminRafflesPage />} />
          <Route path="admin/raffles/new" element={<AdminRaffleFormPage />} />
          <Route path="admin/raffles/:raffleId/edit" element={<AdminRaffleFormPage />} />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  )
}
