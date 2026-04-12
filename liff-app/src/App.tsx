import { lazy, Suspense } from 'react'
import { Routes, Route } from 'react-router-dom'
import { AppProviders } from '@/providers/AppProviders'
import { AppShell } from '@/components/layout/AppShell'
import { LoadingOverlay } from '@/components/layout/LoadingOverlay'

const HomePage = lazy(() => import('@/pages/HomePage').then((m) => ({ default: m.HomePage })))
const ShopPage = lazy(() => import('@/pages/ShopPage').then((m) => ({ default: m.ShopPage })))
const CartPage = lazy(() => import('@/pages/CartPage').then((m) => ({ default: m.CartPage })))
const CheckoutPage = lazy(() => import('@/pages/CheckoutPage').then((m) => ({ default: m.CheckoutPage })))
const OrdersPage = lazy(() => import('@/pages/OrdersPage').then((m) => ({ default: m.OrdersPage })))
const OrderDetailPage = lazy(() => import('@/pages/OrderDetailPage').then((m) => ({ default: m.OrderDetailPage })))
const MemberPage = lazy(() => import('@/pages/MemberPage').then((m) => ({ default: m.MemberPage })))
const ProfilePage = lazy(() => import('@/pages/ProfilePage').then((m) => ({ default: m.ProfilePage })))
const RegisterPage = lazy(() => import('@/pages/RegisterPage').then((m) => ({ default: m.RegisterPage })))
const NotificationsPage = lazy(() => import('@/pages/NotificationsPage').then((m) => ({ default: m.NotificationsPage })))
const WishlistPage = lazy(() => import('@/pages/WishlistPage').then((m) => ({ default: m.WishlistPage })))
const PointsPage = lazy(() => import('@/pages/PointsPage').then((m) => ({ default: m.PointsPage })))
const RedeemPage = lazy(() => import('@/pages/RedeemPage').then((m) => ({ default: m.RedeemPage })))
const AIChatPage = lazy(() => import('@/pages/AIChatPage').then((m) => ({ default: m.AIChatPage })))
const VideoCallPage = lazy(() => import('@/pages/VideoCallPage').then((m) => ({ default: m.VideoCallPage })))
const HealthProfilePage = lazy(() => import('@/pages/HealthProfilePage').then((m) => ({ default: m.HealthProfilePage })))
const AppointmentsPage = lazy(() => import('@/pages/AppointmentsPage').then((m) => ({ default: m.AppointmentsPage })))

export default function App() {
  return (
    <AppProviders>
      <AppShell>
        <Suspense fallback={<LoadingOverlay />}>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/shop" element={<ShopPage />} />
            <Route path="/cart" element={<CartPage />} />
            <Route path="/checkout" element={<CheckoutPage />} />
            <Route path="/orders" element={<OrdersPage />} />
            <Route path="/order/:id" element={<OrderDetailPage />} />
            <Route path="/member" element={<MemberPage />} />
            <Route path="/profile" element={<ProfilePage />} />
            <Route path="/register" element={<RegisterPage />} />
            <Route path="/notifications" element={<NotificationsPage />} />
            <Route path="/wishlist" element={<WishlistPage />} />
            <Route path="/points" element={<PointsPage />} />
            <Route path="/redeem" element={<RedeemPage />} />
            <Route path="/ai-chat" element={<AIChatPage />} />
            <Route path="/video-call/:id?" element={<VideoCallPage />} />
            <Route path="/health-profile" element={<HealthProfilePage />} />
            <Route path="/appointments" element={<AppointmentsPage />} />
          </Routes>
        </Suspense>
      </AppShell>
    </AppProviders>
  )
}
