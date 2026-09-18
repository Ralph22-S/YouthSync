import { Navigate, Route, Routes } from 'react-router-dom';
import { SkLayout, AdminLayout, YouthLayout } from './components/layouts.jsx';
import { Landing, Login, Register, Pending, ForgotPassword, YouthSignup, ChangePassword } from './pages/public.jsx';
import Dashboard from './pages/sk/Dashboard.jsx';
import { YouthList, YouthDetail, YouthForm, YouthImport } from './pages/sk/Youth.jsx';
import { AssistanceList, AssistanceDetail, AssistanceForm } from './pages/sk/Assistance.jsx';
import { ProgramList, ProgramDetail, ProgramForm } from './pages/sk/Programs.jsx';
import { Notifications, Reports, Subscription, Plans, Checkout, Settings } from './pages/sk/Misc.jsx';
import { ApplicationQueue, ApplicationReview } from './pages/sk/Applications.jsx';
import Attendance from './pages/sk/Attendance.jsx';
import SkUsers from './pages/sk/Users.jsx';
import Outbox from './pages/sk/Outbox.jsx';
import ActivityLogs from './pages/sk/Activity.jsx';
import Billing from './pages/sk/Billing.jsx';
import ScanProgram from './pages/youth/Scan.jsx';
import {
  YouthDashboard, YouthProfile, YouthOpportunities, YouthOpportunityDetail,
  YouthApplications, YouthApplicationDetail, YouthNotifications, YouthQr,
  YouthRegistrations, YouthAttendance,
} from './pages/youth/Youth.jsx';
import {
  AdminDashboard, Organizations, OrganizationDetail, OrganizationForm,
  Subscriptions, Payments, Users, Monitoring,
} from './pages/admin/Admin.jsx';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login />} />
      <Route path="/signup" element={<YouthSignup />} />
      <Route path="/register" element={<Register />} />
      <Route path="/register/pending" element={<Pending />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/change-password" element={<ChangePassword />} />

      {/* Youth */}
      <Route path="/youth" element={<YouthLayout />}>
        <Route index element={<YouthDashboard />} />
        <Route path="profile" element={<YouthProfile />} />
        <Route path="events" element={<YouthOpportunities kind="event" />} />
        <Route path="events/:id" element={<YouthOpportunityDetail kind="event" />} />
        <Route path="programs" element={<YouthOpportunities kind="program" />} />
        <Route path="programs/:id" element={<YouthOpportunityDetail kind="program" />} />
        <Route path="assistance" element={<YouthOpportunities kind="assistance" />} />
        <Route path="assistance/:id" element={<YouthOpportunityDetail kind="assistance" />} />
        <Route path="scan" element={<ScanProgram />} />
        <Route path="applications" element={<YouthApplications />} />
        <Route path="applications/:id" element={<YouthApplicationDetail />} />
        <Route path="notifications" element={<YouthNotifications />} />
        <Route path="qr" element={<YouthQr />} />
        <Route path="registrations" element={<YouthRegistrations />} />
        <Route path="attendance" element={<YouthAttendance />} />
      </Route>

      {/* SK official */}
      <Route path="/sk" element={<SkLayout />}>
        <Route index element={<Dashboard />} />
        <Route path="youth" element={<YouthList />} />
        <Route path="youth/new" element={<YouthForm />} />
        <Route path="youth/import" element={<YouthImport />} />
        <Route path="youth/:id" element={<YouthDetail />} />
        <Route path="youth/:id/edit" element={<YouthForm />} />
        <Route path="applications" element={<ApplicationQueue />} />
        <Route path="applications/:id" element={<ApplicationReview />} />
        <Route path="assistance" element={<AssistanceList />} />
        <Route path="assistance/new" element={<AssistanceForm />} />
        <Route path="assistance/:id" element={<AssistanceDetail />} />
        <Route path="assistance/:id/edit" element={<AssistanceForm />} />
        <Route path="programs" element={<ProgramList />} />
        <Route path="programs/new" element={<ProgramForm />} />
        <Route path="programs/:id" element={<ProgramDetail />} />
        <Route path="programs/:id/edit" element={<ProgramForm />} />
        <Route path="attendance" element={<Attendance />} />
        <Route path="users" element={<SkUsers />} />
        <Route path="outbox" element={<Outbox />} />
        <Route path="activity" element={<ActivityLogs />} />
        <Route path="notifications" element={<Notifications />} />
        <Route path="reports" element={<Reports />} />
        <Route path="subscription" element={<Subscription />} />
        <Route path="billing" element={<Billing />} />
        <Route path="subscription/plans" element={<Plans />} />
        <Route path="subscription/checkout" element={<Checkout />} />
        <Route path="settings" element={<Settings />} />
      </Route>

      {/* Admin / customer */}
      <Route path="/admin" element={<AdminLayout />}>
        <Route index element={<AdminDashboard />} />
        <Route path="organizations" element={<Organizations />} />
        <Route path="organizations/new" element={<OrganizationForm />} />
        <Route path="organizations/:id" element={<OrganizationDetail />} />
        <Route path="users" element={<Users />} />
        <Route path="subscriptions" element={<Subscriptions />} />
        <Route path="payments" element={<Payments />} />
        <Route path="monitoring" element={<Monitoring />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
