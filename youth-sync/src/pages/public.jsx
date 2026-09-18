import { useEffect, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useStore } from '../store.jsx';
import { GuestLayout } from '../components/layouts.jsx';
import { Badge, Field } from '../components/ui.jsx';
import EducationFields from '../components/education.jsx';
import { TagInput, CheckboxGroup } from '../components/tags.jsx';
import { digitsOnly, validateContact } from '../lib/validation.js';
import {
  APP_NAME, MUNICIPALITIES, INTEREST_OPTIONS, SKILL_OPTIONS, ACTIVITY_OPTIONS,
} from '../data/mock.js';

const limitLabel = (v) => (v === null ? 'Unlimited' : v.toLocaleString());

export function Landing() {
  const { plans, user, sessionReady } = useStore();

  if (sessionReady === false) {
    return (
      <div className="grid min-h-full place-items-center text-sm text-ink-muted">Checking your session…</div>
    );
  }
  if (user) {
    const to = user.role === 'system_admin' ? '/admin' : user.role === 'youth' ? '/youth' : '/sk';
    return <Navigate to={to} replace />;
  }

  const safeLimit = (value) =>
    value === null || value === undefined ? 'Unlimited' : Number(value).toLocaleString();

  const safeMoney = (value) => Number(value || 0).toLocaleString();

  const rows = [
    ['Youth capacity', (p) => safeLimit(p.limits.youth)],
    ['Accounts', (p) => safeLimit(p.limits.accounts)],
    ['Programs & events', (p) => safeLimit(p.limits.programs)],
    ['Assistance programs', (p) => safeLimit(p.limits.assistance)],
    ['Reports', (p) => p.features.advanced_reports ? 'Advanced' : 'Standard'],
    ['Analytics', (p) => p.features.advanced_analytics ? 'Advanced' : 'Basic'],
    ['CSV import', (p) => p.features.csv_import ? 'Included' : '—'],
    ['Excel / PDF export', (p) => p.features.excel_export ? 'Included' : '—'],
    ['Activity logs', (p) =>
      p.features.audit_trail
        ? 'Detailed audit trail'
        : p.features.activity_logs
          ? 'Standard'
          : '—'
    ],
    ['Backup tools', (p) => p.features.backup_tools ? 'Included' : '—'],
    ['Support', (p) => p.features.priority_support ? 'Priority' : 'Standard'],
  ];

  const features = [
    [
      '01',
      'Youth Records',
      'Maintain organized youth profiles, education, skills, interests, and participation records.'
    ],
    [
      '02',
      'Programs & Events',
      'Create activities, manage registrations, organize participants, and track attendance.'
    ],
    [
      '03',
      'Assistance & Scholarships',
      'Configure requirements, review applications, and manage approved beneficiaries.'
    ],
    [
      '04',
      'Attendance',
      'Use QR-based attendance to record actual participation with clear timestamps.'
    ],
    [
      '05',
      'Reports',
      'Turn daily SK records into organized reports and downloadable information.'
    ],
    [
      '06',
      'Notifications',
      'Keep youth informed about applications, activities, requirements, and decisions.'
    ],
  ];

  const steps = [
    [
      '01',
      'Register your SK',
      'Submit your barangay, municipality, and organization details.'
    ],
    [
      '02',
      'Get verified',
      'The system administrator reviews and verifies your organization.'
    ],
    [
      '03',
      'Set up your SK',
      'Add youth records, programs, assistance, attendance, and settings.'
    ],
    [
      '04',
      'Serve your youth',
      'Let youth discover programs, apply for assistance, and track participation.'
    ],
  ];

  const faqs = [
    [
      'What is YouthSync?',
      'YouthSync is a centralized platform for Sangguniang Kabataan organizations to manage youth records, programs, assistance, attendance, notifications, and reports.'
    ],
    [
      'What happens when the 7-day Premium trial ends?',
      'Your organization moves to the Free plan automatically. Existing records remain available while plan limits and features follow the Free plan.'
    ],
    [
      'Does the Free plan expire?',
      'No. The Free plan is permanent. Your organization can continue using it within the included plan limits.'
    ],
    [
      'Can another SK organization see our records?',
      'No. Organization records are separated so each SK organization can access only its own information.'
    ],
    [
      'Does YouthSync automatically approve beneficiaries?',
      'No. The system can provide eligibility or priority recommendations, but the final decision remains with the SK official.'
    ],
    [
      'Can youth register their own account?',
      'Yes. Youth can create an account and complete the required profile information.'
    ],
  ];

  return (
    <div
      id="top"
      className="min-h-full bg-white text-navy-900"
      style={{ scrollBehavior: 'smooth' }}
    >

      {/* =========================================================
          NAVIGATION
      ========================================================== */}
      <header className="sticky top-0 z-50 border-b border-white/10 bg-[#071521]/95 text-white backdrop-blur-xl">
        <div className="mx-auto flex min-h-[74px] max-w-7xl items-center px-5 lg:px-8">

          <a href="#top" className="group flex shrink-0 items-center gap-3">
            <span className="grid h-10 w-10 place-items-center bg-sun text-sm font-black text-navy-900 transition duration-300 group-hover:scale-105">
              YS
            </span>

            <div className="hidden sm:block">
              <p className="text-[15px] font-black tracking-tight text-white">
                YouthSync
              </p>

              <p className="mt-0.5 text-[9px] font-semibold uppercase tracking-[0.2em] text-white/45">
                SK Management Platform
              </p>
            </div>
          </a>

          <nav className="ml-auto hidden items-center md:flex">

            <div className="mr-8 flex items-center gap-8">
              {[
                ['Features', '#features'],
                ['How it works', '#how'],
                ['Pricing', '#pricing'],
                ['FAQ', '#faq'],
              ].map(([label, href]) => (
                <a
                  key={href}
                  href={href}
                  className="group relative py-7 text-[13px] font-semibold text-white/65 transition hover:text-white"
                >
                  {label}

                  <span className="absolute inset-x-0 bottom-0 h-0.5 origin-left scale-x-0 bg-sun transition-transform duration-300 group-hover:scale-x-100" />
                </a>
              ))}
            </div>

            <div className="flex items-center gap-3 border-l border-white/10 pl-7">
              <Link
                to="/login"
                className="px-3 py-2 text-[13px] font-bold text-white/80 transition hover:text-white"
              >
                Log in
              </Link>

              <Link
                to="/register"
                className="bg-sun px-5 py-2.5 text-[13px] font-extrabold text-navy-900 transition hover:-translate-y-0.5 hover:brightness-105"
              >
                Start free
              </Link>
            </div>

          </nav>

          {/* Mobile */}
          <div className="ml-auto flex items-center gap-2 md:hidden">
            <Link
              to="/login"
              className="px-3 py-2 text-xs font-bold text-white"
            >
              Log in
            </Link>

            <Link
              to="/register"
              className="bg-sun px-4 py-2.5 text-xs font-extrabold text-navy-900"
            >
              Start free
            </Link>
          </div>

        </div>
      </header>


      {/* =========================================================
          HERO
      ========================================================== */}
      <section
        id="hero"
        className="relative overflow-hidden bg-[#071A2A] text-white"
      >
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_75%_25%,rgba(247,196,66,.09),transparent_28%),radial-gradient(circle_at_15%_80%,rgba(35,86,125,.18),transparent_35%)]" />

        <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-5 py-20 lg:grid-cols-[1.05fr_.95fr] lg:px-8 lg:py-28">

          <div>

            <p className="text-xs font-bold uppercase tracking-[0.18em] text-sun">
              SK Management Platform
            </p>

            <h1 className="mt-5 max-w-3xl text-5xl font-black leading-[0.98] tracking-[-0.04em] text-white sm:text-6xl lg:text-[70px]">
              Run your SK.
              <br />
              <span className="text-sun">
                Serve your youth.
              </span>
            </h1>

            <p className="mt-7 max-w-xl text-[15px] leading-7 text-[#B7C6D6] sm:text-base">
              A centralized platform for managing youth records, programs,
              assistance, events, attendance, notifications, and reports.
            </p>

            <div className="mt-9 flex flex-wrap gap-3">
              <Link
                to="/register"
                className="btn btn-accent min-w-[130px] justify-center"
              >
                Start free
              </Link>

              <a
                href="#features"
                className="btn border border-[#355674] bg-transparent text-white hover:bg-[#102D49]"
              >
                Explore YouthSync
              </a>
            </div>

            <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-xs text-[#8FA6BF]">
              <span>No payment required</span>
              <span>Free plan available</span>
              <span>Built for SK organizations</span>
            </div>

          </div>


          {/* PRODUCT PREVIEW */}
          <div className="relative">

            <div className="relative overflow-hidden border border-[#31516C] bg-[#0E293E] shadow-2xl">

              <div className="flex items-center justify-between border-b border-[#28465D] px-5 py-4">
                <div>
                  <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-[#7190A9]">
                    YouthSync
                  </p>

                  <p className="mt-1 text-sm font-bold text-white">
                    SK Organization
                  </p>
                </div>

                <span className="h-3 w-3 rounded-full bg-sun shadow-[0_0_15px_rgba(247,196,66,.5)]" />
              </div>


              <div className="grid grid-cols-[145px_1fr]">

                <aside className="border-r border-[#28465D] bg-[#081D2D] p-4">

                  <div className="mb-6 h-9 w-24 bg-sun" />

                  <div className="space-y-1">
                    {[
                      'Dashboard',
                      'Youth Records',
                      'Programs & Events',
                      'Applications',
                      'Attendance',
                      'Reports',
                    ].map((item, index) => (
                      <div
                        key={item}
                        className={`px-3 py-3 text-[10px] font-semibold ${
                          index === 0
                            ? 'bg-[#20384A] text-white'
                            : 'text-[#7893A9]'
                        }`}
                      >
                        {item}
                      </div>
                    ))}
                  </div>

                </aside>


                <div className="p-6">

                  <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-[#6E8AA0]">
                    Overview
                  </p>

                  <h3 className="mt-2 text-xl font-bold text-white">
                    Everything in one place.
                  </h3>

                  <div className="mt-6 grid grid-cols-2 gap-3">

                    {[
                      ['Youth Records', 'Organized profiles'],
                      ['Programs', 'Active activities'],
                      ['Applications', 'Review & decisions'],
                      ['Attendance', 'QR verification'],
                    ].map(([title, subtitle]) => (
                      <div
                        key={title}
                        className="border border-[#345267] bg-[#162F41] p-4"
                      >
                        <div className="h-1 w-8 bg-sun" />

                        <p className="mt-5 text-xs font-bold text-white">
                          {title}
                        </p>

                        <p className="mt-2 text-[10px] text-[#7190A9]">
                          {subtitle}
                        </p>
                      </div>
                    ))}

                  </div>

                  <div className="mt-4 border border-[#345267] bg-[#162F41] p-4">

                    <div className="flex items-center justify-between border-b border-[#29475B] pb-3">
                      <p className="text-xs font-bold text-[#DCE6EE]">
                        Recent activity
                      </p>

                      <span className="text-[10px] text-sun">
                        View all
                      </span>
                    </div>

                    {[
                      'New youth registration',
                      'Program application received',
                      'Attendance recorded',
                    ].map((item) => (
                      <div
                        key={item}
                        className="flex items-center gap-3 border-b border-[#29475B] py-3 last:border-0"
                      >
                        <span className="h-1.5 w-1.5 rounded-full bg-sun" />

                        <span className="text-[10px] text-[#8EA7BA]">
                          {item}
                        </span>
                      </div>
                    ))}

                  </div>

                </div>

              </div>
            </div>

          </div>

        </div>
      </section>


      {/* =========================================================
          FEATURES
      ========================================================== */}
      <section id="features" className="bg-white">
        <div className="mx-auto max-w-7xl px-5 py-20 lg:px-8">

          <div className="max-w-2xl">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-[#8391A4]">
              Platform
            </p>

            <h2 className="mt-3 text-3xl font-black tracking-tight text-navy-900 sm:text-4xl">
              Everything your SK needs to manage.
            </h2>

            <p className="mt-4 text-sm leading-7 text-[#5A6C82]">
              Keep records, programs, assistance, attendance, and
              communication organized in one system.
            </p>
          </div>


          <div className="mt-10 grid gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">

            {features.map(([number, title, body]) => (
              <div
                key={title}
                className="bg-white p-7 transition hover:bg-[#F8FAFC]"
              >
                <span className="text-xs font-black tracking-[0.15em] text-sun">
                  {number}
                </span>

                <h3 className="mt-5 text-sm font-bold text-navy-900">
                  {title}
                </h3>

                <p className="mt-2 text-sm leading-6 text-[#5A6C82]">
                  {body}
                </p>
              </div>
            ))}

          </div>

        </div>
      </section>


      {/* =========================================================
          HOW IT WORKS
      ========================================================== */}
      <section id="how" className="border-y border-line bg-[#F4F7FA]">
        <div className="mx-auto max-w-7xl px-5 py-20 lg:px-8">

          <div className="max-w-2xl">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-[#8391A4]">
              Simple workflow
            </p>

            <h2 className="mt-3 text-3xl font-black tracking-tight text-navy-900">
              From setup to service.
            </h2>
          </div>

          <ol className="mt-10 grid border border-line sm:grid-cols-2 lg:grid-cols-4">

            {steps.map(([number, title, body]) => (
              <li
                key={number}
                className="border-b border-line bg-white p-7 last:border-b-0 sm:border-r lg:border-b-0"
              >
                <span className="text-3xl font-black text-sun">
                  {number}
                </span>

                <h3 className="mt-6 text-sm font-bold text-navy-900">
                  {title}
                </h3>

                <p className="mt-2 text-sm leading-6 text-[#5A6C82]">
                  {body}
                </p>
              </li>
            ))}

          </ol>

        </div>
      </section>


      {/* =========================================================
          PRICING
      ========================================================== */}
      <section
        id="pricing"
        className="bg-[#071A2A] text-white"
      >
        <div className="mx-auto max-w-7xl px-5 py-20 lg:px-8">

          <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">

            <div>
              <p className="text-xs font-bold uppercase tracking-[0.16em] text-sun">
                Subscription
              </p>

              <h2 className="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">
                Plans that scale with your SK.
              </h2>

              <p className="mt-3 max-w-xl text-sm leading-6 text-[#9CB0C1]">
                Choose the plan that fits your organization. Upgrade when
                your needs grow.
              </p>
            </div>

            <p className="text-xs text-[#7893A9]">
              One subscription per SK organization.
            </p>

          </div>


          {/* PLAN CARDS */}
          <div className="mt-10 grid gap-4 lg:grid-cols-3">

            {plans.map((plan) => (
              <div
                key={plan.code}
                className={`relative flex flex-col border p-6 ${
                  plan.popular
                    ? 'border-sun bg-[#102D49] shadow-[0_20px_50px_rgba(0,0,0,.22)]'
                    : 'border-[#29485F] bg-[#0D2639]'
                }`}
              >

                {plan.popular && (
                  <span className="absolute right-5 top-5 bg-sun px-3 py-1 text-[9px] font-black uppercase tracking-[0.12em] text-navy-900">
                    Most popular
                  </span>
                )}

                <h3 className="text-lg font-black text-white">
                  {plan.name}
                </h3>

                <p className="mt-2 max-w-xs text-sm leading-6 text-[#8FA6B8]">
                  {plan.tagline}
                </p>

                <div className="mt-7">
                  <span className="text-4xl font-black text-white">
                    ₱{safeMoney(plan.priceMonthly)}
                  </span>

                  {plan.priceMonthly > 0 && (
                    <span className="ml-1 text-sm text-[#8199AB]">
                      / month
                    </span>
                  )}
                </div>

                <p className="mt-1 text-xs text-[#8199AB]">
                  {plan.priceYearly > 0
                    ? `or ₱${safeMoney(plan.priceYearly)} / year`
                    : 'Permanent. Does not expire.'}
                </p>

                <div className="my-6 h-px bg-[#29485F]" />

                <ul className="flex-1 space-y-3 text-sm text-[#B8C8D5]">
                  <li>{safeLimit(plan.limits.youth)} youth records</li>
                  <li>{safeLimit(plan.limits.accounts)} staff accounts</li>
                  <li>{safeLimit(plan.limits.programs)} programs & events</li>
                  <li>{safeLimit(plan.limits.assistance)} assistance programs</li>
                  <li>
                    {plan.features.csv_import
                      ? 'CSV import included'
                      : 'Manual data entry'}
                  </li>
                  <li>
                    {plan.features.advanced_reports
                      ? 'Advanced reports'
                      : 'Standard reports'}
                  </li>
                </ul>

                <Link
                  to="/register"
                  className={`mt-7 flex items-center justify-center px-5 py-3 text-sm font-bold transition ${
                    plan.popular
                      ? 'bg-sun text-navy-900 hover:brightness-105'
                      : 'border border-[#466178] text-white hover:bg-[#162F41]'
                  }`}
                >
                  {plan.priceMonthly > 0
                    ? `Choose ${plan.name}`
                    : 'Start free'}
                </Link>

              </div>
            ))}

          </div>


          {/* COMPARISON — NOT WHITE */}
          <div className="mt-8 overflow-hidden border border-[#29485F] bg-[#0D2639]">

            <div className="border-b border-[#29485F] px-6 py-5">
              <p className="text-xs font-bold uppercase tracking-[0.15em] text-sun">
                Compare plans
              </p>

              <p className="mt-1 text-sm text-[#8FA6B8]">
                See what is included with each subscription.
              </p>
            </div>

            <div className="overflow-x-auto">

              <table
                className="w-full"
                style={{ minWidth: 760 }}
              >
                <thead>
                  <tr className="border-b border-[#29485F] bg-[#102D49]">
                    <th className="px-6 py-4 text-left text-[11px] font-bold uppercase tracking-[0.1em] text-[#91A8B9]">
                      Feature
                    </th>

                    {plans.map((plan) => (
                      <th
                        key={plan.code}
                        className={`px-6 py-4 text-left text-[11px] font-bold uppercase tracking-[0.1em] ${
                          plan.popular
                            ? 'text-sun'
                            : 'text-[#91A8B9]'
                        }`}
                      >
                        {plan.name}
                      </th>
                    ))}
                  </tr>
                </thead>

                <tbody>

                  {rows.map(([label, resolve]) => (
                    <tr
                      key={label}
                      className="border-b border-[#223F53] last:border-0"
                    >
                      <td className="px-6 py-4 text-sm font-semibold text-white">
                        {label}
                      </td>

                      {plans.map((plan) => (
                        <td
                          key={plan.code}
                          className={`px-6 py-4 text-sm ${
                            plan.popular
                              ? 'font-semibold text-[#E7D38A]'
                              : 'text-[#91A8B9]'
                          }`}
                        >
                          {resolve(plan)}
                        </td>
                      ))}
                    </tr>
                  ))}

                </tbody>
              </table>

            </div>
          </div>

        </div>
      </section>


      {/* =========================================================
          FAQ
      ========================================================== */}
      <section id="faq" className="bg-white">
        <div className="mx-auto max-w-4xl px-5 py-20">

          <p className="text-xs font-bold uppercase tracking-[0.16em] text-[#8391A4]">
            FAQ
          </p>

          <h2 className="mt-3 text-3xl font-black tracking-tight text-navy-900">
            Frequently asked questions.
          </h2>

          <div className="mt-8 divide-y divide-line border-y border-line">

            {faqs.map(([question, answer]) => (
              <details
                key={question}
                className="group py-5"
              >
                <summary className="cursor-pointer list-none pr-8 text-sm font-bold text-navy-900">
                  {question}
                </summary>

                <p className="mt-3 max-w-3xl text-sm leading-6 text-[#5A6C82]">
                  {answer}
                </p>
              </details>
            ))}

          </div>

        </div>
      </section>


      {/* =========================================================
          CTA
      ========================================================== */}
      <section className="bg-[#071A2A] text-white">

        <div className="mx-auto flex max-w-7xl flex-col gap-7 px-5 py-16 lg:flex-row lg:items-center lg:justify-between lg:px-8">

          <div>
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-sun">
              YouthSync
            </p>

          <h2 className="mt-3 text-3xl font-black tracking-tight text-white">
            Ready to run your SK better?
          </h2>

            <p className="mt-3 text-sm text-[#9CB0C1]">
              Set up your organization and start managing your youth community
              in one place.
            </p>
          </div>

          <div className="flex flex-wrap gap-3">
            <Link
              to="/register"
              className="btn btn-accent"
            >
              Register your SK
            </Link>

            <Link
              to="/login"
              className="btn border border-[#3A5870] text-white hover:bg-[#102D49]"
            >
              Log in
            </Link>
          </div>

        </div>

      </section>


      <footer className="border-t border-[#20394D] bg-[#071521] py-8 text-center text-xs text-[#71879A]">
        <p>
          YouthSync · SK Management Platform
        </p>
      </footer>

    </div>
  );
}


export function Login() {
  const { login, user, sessionReady } = useStore();
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [remember, setRemember] = useState(false);

  if (sessionReady === false) {
    return <GuestLayout><p className="text-sm text-ink-muted">Checking your session…</p></GuestLayout>;
  }
  if (user) {
    return (
      <Navigate
        to={user.role === 'system_admin' ? '/admin' : user.role === 'youth' ? '/youth' : '/sk'}
        replace
      />
    );
  }

  const submit = async (event) => {
    event.preventDefault();

    setError('');
    setBusy(true);

    try {
      const result = await login(email, password, remember);

      if (result.error) {
        setError(result.error);
        return;
      }

      navigate(
        result.user.role === 'system_admin'
          ? '/admin'
          : result.user.role === 'youth'
            ? '/youth'
            : '/sk'
      );
    } catch (err) {
      setError(
        err?.message || 'Unable to sign in. Please try again.'
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <GuestLayout>

      <div className="min-h-[calc(100vh-120px)] px-4 py-6 sm:px-6 sm:py-10">

        <div className="mx-auto grid w-full max-w-[980px] overflow-hidden rounded-[26px] border border-[#D8E0E8] bg-white shadow-[0_25px_70px_rgba(7,21,33,.12)] lg:grid-cols-[46%_54%]">


          {/* =====================================================
              LEFT BRAND SIDE
          ====================================================== */}
          <section className="relative overflow-hidden bg-[#082139] px-8 py-10 text-white sm:px-10 lg:px-11">

            {/* subtle background */}
            <div className="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-[#163C5B] opacity-50 blur-3xl" />

            <div className="absolute -bottom-28 -left-20 h-72 w-72 rounded-full bg-[#0E304D] opacity-70 blur-3xl" />

            <div className="relative flex min-h-[620px] flex-col">

              {/* BRAND */}
              <div className="flex items-center gap-3">

                <span className="grid h-11 w-11 place-items-center rounded-xl bg-white text-sm font-black text-[#082139]">
                  YS
                </span>

                <div>
                  <p className="text-base font-black text-white">
                    YouthSync
                  </p>

                  <p className="mt-0.5 text-[9px] uppercase tracking-[0.18em] text-[#8FA8BD]">
                    SK Management Platform
                  </p>
                </div>

              </div>


              {/* MESSAGE */}
              <div className="mt-24 max-w-[390px]">

                <p className="text-xs font-black uppercase tracking-[0.16em] text-sun">
                  Welcome to YouthSync
                </p>

                <h2 className="mt-5 text-4xl font-black leading-[1.08] tracking-[-0.03em] text-white sm:text-[42px]">
                  Connecting Youth.
                  <br />
                  <span className="text-sun">
                    Empowering Communities.
                  </span>
                </h2>

                <p className="mt-6 max-w-[350px] text-sm leading-7 text-[#B7C8D8]">
                  A centralized platform that helps Sangguniang Kabataan
                  organizations manage youth records, programs, assistance,
                  attendance, and community activities.
                </p>

              </div>


              {/* BOTTOM POINTS */}
              <div className="mt-auto space-y-4 pt-12">

                {[
                  'Organized youth records',
                  'Programs and assistance management',
                  'Attendance and participation tracking',
                ].map((item) => (
                  <div
                    key={item}
                    className="flex items-center gap-3"
                  >
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[#173A59] text-xs font-black text-sun">
                      ✓
                    </span>

                    <span className="text-sm text-[#B7C8D8]">
                      {item}
                    </span>
                  </div>
                ))}

              </div>

            </div>
          </section>


          {/* =====================================================
              RIGHT LOGIN SIDE
          ====================================================== */}
          <section className="flex items-center bg-white px-7 py-10 sm:px-10 lg:px-12">

            <div className="mx-auto w-full max-w-[390px]">

              {/* mobile branding */}
              <div className="mb-9 flex items-center gap-3 lg:hidden">

                <span className="grid h-10 w-10 place-items-center rounded-xl bg-[#082139] text-xs font-black text-sun">
                  YS
                </span>

                <div>
                  <p className="text-sm font-black text-navy-900">
                    YouthSync
                  </p>

                  <p className="text-[9px] uppercase tracking-[0.14em] text-[#8795A6]">
                    SK Management Platform
                  </p>
                </div>

              </div>


              {/* heading */}
              <div>

                <p className="text-xs font-black uppercase tracking-[0.16em] text-[#8795A6]">
                  Account access
                </p>

                <h1 className="mt-3 text-4xl font-black leading-none tracking-[-0.03em] text-navy-900">
                  Welcome
                  <br />
                  back
                </h1>

                <p className="mt-4 max-w-[260px] text-sm leading-7 text-[#5A6C82]">
                  Sign in to your YouthSync account.
                </p>

              </div>


              {/* error */}
              {error && (
                <div className="mt-6 border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">
                  {error}
                </div>
              )}


              {/* form */}
              <form
                onSubmit={submit}
                className="mt-8 space-y-5"
              >

                <Field label="Email address *">
                  <input
                    className="field"
                    type="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    placeholder="you@example.com"
                    autoComplete="email"
                    required
                  />
                </Field>


                <Field label="Password *">
                  <div className="relative">

                    <input
                      className="field pr-16"
                      type={show ? 'text' : 'password'}
                      value={password}
                      onChange={(event) => setPassword(event.target.value)}
                      placeholder="Enter your password"
                      autoComplete="current-password"
                      required
                    />

                    <button
                      type="button"
                      onClick={() => setShow((value) => !value)}
                      className="absolute inset-y-0 right-2 my-auto h-8 rounded-md px-2 text-xs font-bold text-[#5A6C82] hover:bg-[#F1F4F7]"
                    >
                      {show ? 'Hide' : 'Show'}
                    </button>

                  </div>
                </Field>


                <div className="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">

                  <label className="flex items-center gap-2 text-[#4A5B70]">
                    <input
                      type="checkbox"
                      checked={remember}
                      onChange={(event) => setRemember(event.target.checked)}
                      className="h-4 w-4 rounded border-[#C7D0DA]"
                    />

                    <span>Remember me</span>
                  </label>

                  <Link
                    to="/forgot-password"
                    className="font-semibold text-navy-900 underline underline-offset-2 hover:text-navy-700"
                  >
                    Forgot password?
                  </Link>

                </div>


                <button
                  type="submit"
                  className="btn btn-primary mt-2 w-full"
                  disabled={busy}
                >
                  {busy ? 'Signing in…' : 'Sign in'}
                </button>

              </form>


              {/* bottom links */}
              <div className="mt-7 border-t border-line pt-6 text-sm leading-7 text-[#5A6C82]">

                <p>
                  New to YouthSync?{' '}
                  <Link
                    to="/signup"
                    className="font-bold text-navy-900 underline underline-offset-2"
                  >
                    Create a youth account
                  </Link>
                </p>

                <p className="mt-1">
                  Managing an SK council?{' '}
                  <Link
                    to="/register"
                    className="font-bold text-navy-900 underline underline-offset-2"
                  >
                    Register your organization
                  </Link>
                </p>

              </div>

            </div>

          </section>

        </div>

      </div>

    </GuestLayout>
  );
}

export function Register() {
  const navigate = useNavigate();
  return (
    <GuestLayout>
      <div className="card p-6">
        <h1 className="text-lg font-bold text-navy-900">Register your SK organization</h1>
        <p className="mt-1 text-sm text-[#7A889B]">Your account stays pending until the system administrator verifies the organization.</p>
        <form className="mt-6 space-y-6" onSubmit={(e) => { e.preventDefault(); navigate('/register/pending'); }}>
          <fieldset className="space-y-4">
            <legend className="text-sm font-semibold text-navy-900">Organization information</legend>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Barangay name"><input className="field" required /></Field>
              <Field label="Municipality">
                <input className="field" list="municipalities" required />
                <datalist id="municipalities">{MUNICIPALITIES.map((m) => <option key={m} value={m} />)}</datalist>
              </Field>
              <Field label="Province"><input className="field" defaultValue="Laguna" required /></Field>
              <Field label="SK chairperson"><input className="field" required /></Field>
              <Field label="Official email"><input className="field" type="email" required /></Field>
              <Field label="Contact number"><input className="field" required /></Field>
            </div>
          </fieldset>
          <fieldset className="space-y-4">
            <legend className="text-sm font-semibold text-navy-900">Your account</legend>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Full name"><input className="field" required /></Field>
              <Field label="Email"><input className="field" type="email" required /></Field>
              <Field label="Password"><input className="field" type="password" required /></Field>
              <Field label="Confirm password"><input className="field" type="password" required /></Field>
            </div>
            <p className="text-xs text-[#8391A4]">At least 8 characters, with letters and numbers.</p>
          </fieldset>
          <div className="space-y-2 text-sm text-[#4A5B70]">
            <label className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-[#C7D0DA]" required /> I accept the Terms and Conditions.</label>
            <label className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-[#C7D0DA]" required /> I accept the Privacy Policy.</label>
          </div>
          <button type="submit" className="btn btn-primary w-full">Submit registration</button>
        </form>
      </div>
    </GuestLayout>
  );
}

export function Pending() {
  return (
    <GuestLayout>
      <div className="card p-6 text-center">
        <span className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-sun-100 text-lg">⏳</span>
        <h1 className="mt-4 text-lg font-bold text-navy-900">Registration submitted</h1>
        <p className="mx-auto mt-2 max-w-sm text-sm text-[#5A6C82]">
          Your organization status is <strong>Pending verification</strong>. Once the system administrator approves it,
          you can log in and your 7-day Premium trial starts automatically.
        </p>
        <Link to="/login" className="btn btn-primary mt-6">Back to log in</Link>
      </div>
    </GuestLayout>
  );
}

export function ForgotPassword() {
  const [sent, setSent] = useState(false);
  return (
    <GuestLayout>
      <div className="card p-6">
        <h1 className="text-lg font-bold text-navy-900">Forgot your password?</h1>
        <p className="mt-1 text-sm text-[#7A889B]">Enter your email and we will create a reset link for you.</p>
        {sent && <p className="mt-4 rounded-card border border-line bg-shell px-4 py-3 text-sm text-navy-700">Reset link created. In the real build this is emailed to you.</p>}
        <form className="mt-5 space-y-4" onSubmit={(e) => { e.preventDefault(); setSent(true); }}>
          <Field label="Email"><input className="field" type="email" required /></Field>
          <button type="submit" className="btn btn-primary w-full">Create reset link</button>
        </form>
        <p className="mt-4 text-sm"><Link to="/login" className="text-navy-700 underline">Back to log in</Link></p>
      </div>
    </GuestLayout>
  );
}


const SIGNUP_EMPTY = {
  firstName: '', middleName: '', lastName: '', birthDate: '', gender: 'Male',
  address: '', mobile: '', email: '',
  educationStatus: 'Currently Studying', education: 'Senior High School',
  school: '', course: '', yearLevel: '', strand: '',
  interests: [], skills: [], preferredActivities: [],
  orgId: '', password: '', confirm: '',
};

/** Youth sign-up: personal details → contact → password → OTP → account activated. */
export function YouthSignup() {
  const { orgs, startSignup, verifyOtp, resendOtp, cancelSignup, signup, apiMode, signupOrganizations } = useStore();
  const [remoteOrgs, setRemoteOrgs] = useState(null);
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [values, setValues] = useState(SIGNUP_EMPTY);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [lastCode, setLastCode] = useState('');
  const [interestError, setInterestError] = useState('');
  const [mobileError, setMobileError] = useState('');

  const set = (key) => (e) => setValues((v) => ({ ...v, [key]: e.target.value }));

  useEffect(() => {
    if (!apiMode || !signupOrganizations) return;
    // Signing up is unauthenticated, so the barangay list has its own public endpoint.
    signupOrganizations().then(setRemoteOrgs).catch(() => setRemoteOrgs([]));
  }, [apiMode, signupOrganizations]);

  const openOrgs = apiMode ? (remoteOrgs ?? []) : orgs.filter((o) => o.status === 'active');

  const goToOtp = async (event) => {
    event.preventDefault();
    setError('');
    if (values.password.length < 8) { setError('Use a password of at least 8 characters.'); return; }
    if (values.password !== values.confirm) { setError('The two passwords do not match.'); return; }
    const result = await startSignup(values);
    if (result.error) { setError(result.error); return; }
    setLastCode(result.code);
    setStep(4);
  };

  const submitOtp = async (event) => {
    event.preventDefault();
    setError('');
    const result = await verifyOtp(code);
    if (result.error) { setError(result.error); return; }
    navigate('/youth');
  };

  const steps = ['Personal information', 'Contact details', 'Password', 'OTP verification'];

  return (
    <GuestLayout>
      <div className="card p-6">
        <h1 className="text-lg font-bold text-navy-900">Create your youth account</h1>
        <p className="mt-1 text-sm text-[#7A889B]">Four short steps. You will verify with a one-time code at the end.</p>

        <ol className="mt-5 flex flex-wrap gap-3 border-b border-line pb-4 text-xs">
          {steps.map((label, index) => (
            <li key={label} className={`flex items-center gap-1.5 ${index + 1 <= step ? 'font-semibold text-navy-900' : 'text-[#9AA7B6]'}`}>
              <span className={`grid h-5 w-5 place-items-center rounded-full ${index + 1 <= step ? 'bg-navy-900 text-white' : 'bg-[#EDF0F4]'}`}>{index + 1}</span>
              {label}
            </li>
          ))}
        </ol>

        {error && <p className="mt-4 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">{error}</p>}

        {step === 1 && (
          <form className="mt-5 space-y-4" onSubmit={(e) => {
            e.preventDefault();
            if (!values.interests.length) { setInterestError('At least one interest is required.'); return; }
            setInterestError(''); setError(''); setStep(2);
          }}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="First name"><input className="field" value={values.firstName} onChange={set('firstName')} required /></Field>
              <Field label="Middle name"><input className="field" value={values.middleName} onChange={set('middleName')} /></Field>
              <Field label="Last name"><input className="field" value={values.lastName} onChange={set('lastName')} required /></Field>
              <Field label="Date of birth"><input className="field" type="date" value={values.birthDate} onChange={set('birthDate')} required /></Field>
              <Field label="Gender">
                <select className="field" value={values.gender} onChange={set('gender')}>
                  {['Male', 'Female', 'Prefer not to say'].map((g) => <option key={g}>{g}</option>)}
                </select>
              </Field>
            </div>

            <Field label="Address"><input className="field" value={values.address} onChange={set('address')} required /></Field>

            <div className="rounded-card border border-line p-4">
              <p className="mb-3 text-sm font-semibold text-navy-900">Education</p>
              <EducationFields values={values} onChange={setValues} />
            </div>

            <TagInput label="Interests" required addLabel="Add Interest"
              values={values.interests} options={INTEREST_OPTIONS}
              onChange={(v) => setValues((x) => ({ ...x, interests: v }))}
              error={interestError}
              placeholder="No interests added yet."
              hint="At least one is required. Your SK uses these to recommend programs." />

            <TagInput label="Skills" addLabel="Add Skill"
              values={values.skills} options={SKILL_OPTIONS}
              onChange={(v) => setValues((x) => ({ ...x, skills: v }))}
              placeholder="No skills added yet." />

            <CheckboxGroup label="Preferred activities" options={ACTIVITY_OPTIONS}
              values={values.preferredActivities}
              onChange={(v) => setValues((x) => ({ ...x, preferredActivities: v }))} />

            <button type="submit" className="btn btn-primary w-full">Continue</button>
          </form>
        )}

        {step === 2 && (
          <form className="mt-5 space-y-4" onSubmit={(e) => {
            e.preventDefault();
            const problem = validateContact(values.mobile);
            if (problem) { setMobileError(problem); return; }
            setMobileError(''); setError(''); setStep(3);
          }}>
            <Field label="Your SK barangay" hint="Your applications go to this SK council.">
              <select className="field" value={values.orgId} onChange={set('orgId')} required>
                <option value="">Select your barangay</option>
                {openOrgs.map((o) => <option key={o.id} value={o.id}>{o.barangay}, {o.municipality}</option>)}
              </select>
            </Field>
            <Field label="Mobile number" error={mobileError}
              hint="Exactly 11 digits, numbers only. Example: 09171234567">
              <input className="field" type="tel" inputMode="numeric" maxLength={11}
                value={values.mobile} placeholder="09171234567"
                onChange={(e) => setValues((x) => ({ ...x, mobile: digitsOnly(e.target.value) }))} required />
            </Field>
            <Field label="Email address"><input className="field" type="email" value={values.email} onChange={set('email')} required /></Field>
            <div className="flex gap-3">
              <button type="button" className="btn btn-ghost" onClick={() => setStep(1)}>Back</button>
              <button type="submit" className="btn btn-primary flex-1">Continue</button>
            </div>
          </form>
        )}

        {step === 3 && (
          <form className="mt-5 space-y-4" onSubmit={goToOtp}>
            <Field label="Create a password" hint="At least 8 characters.">
              <input className="field" type="password" value={values.password} onChange={set('password')} required />
            </Field>
            <Field label="Confirm password">
              <input className="field" type="password" value={values.confirm} onChange={set('confirm')} required />
            </Field>
            <div className="flex gap-3">
              <button type="button" className="btn btn-ghost" onClick={() => setStep(2)}>Back</button>
              <button type="submit" className="btn btn-primary flex-1">Send OTP</button>
            </div>
          </form>
        )}

        {step === 4 && (
          <form className="mt-5 space-y-4" onSubmit={submitOtp}>
            <p className="text-sm text-[#5A6C82]">
              We sent a 6-digit code to <strong>{values.mobile}</strong> and <strong>{values.email}</strong>. It expires in 5 minutes.
            </p>
            {lastCode ? (
              <div className="rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]">
                <p className="font-semibold">Development mode — the code is shown here.</p>
                <p className="mt-1">
                  Your code is <span className="font-mono text-base font-bold">{lastCode}</span>. With a Semaphore
                  API key configured (and SK_EXPOSE_OTP=false) it is sent by SMS instead.
                </p>
              </div>
            ) : (
              <div className="rounded-card border border-line bg-shell px-4 py-3 text-sm text-[#5A6C82]">
                The code was sent by SMS to {values.mobile}.
              </div>
            )}
            <Field label="Enter the 6-digit code">
              <input className="field text-center font-mono text-lg tracking-[0.4em]" value={code} maxLength={6}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required />
            </Field>
            <button type="submit" className="btn btn-primary w-full">Verify and activate my account</button>
            <div className="flex justify-between text-sm">
              <button type="button" className="text-navy-700 underline"
                onClick={async () => {
                const r = await resendOtp();
                if (r.error) { setError(r.error); return; }
                setLastCode(r.code); setCode(''); setError('');
              }}>
                Resend code
              </button>
              <button type="button" className="text-[#5A6C82] underline" onClick={() => { cancelSignup(); setStep(3); }}>
                Change my details
              </button>
            </div>
            {signup && <p className="text-xs text-[#8391A4]">Attempts used: {signup.attempts} of 5</p>}
          </form>
        )}
      </div>
    </GuestLayout>
  );
}


/** Required once, when a youth signs in with a temporary password from the SK. */
export function ChangePassword() {
  const { user, changePassword } = useStore();
  const navigate = useNavigate();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');

  if (!user) return <Navigate to="/login" replace />;
  if (!user.mustChangePassword) return <Navigate to={user.role === 'youth' ? '/youth' : '/sk'} replace />;

  const submit = async (event) => {
    event.preventDefault();
    if (!currentPassword) { setError('Current password is required.'); return; }
    if (password.length < 8) { setError('Use a password of at least 8 characters.'); return; }
    if (password !== confirm) { setError('The two passwords do not match.'); return; }
    const ok = await changePassword(password, currentPassword);
    if (ok) navigate(user.role === 'youth' ? '/youth' : '/sk', { replace: true });
  };

  return (
    <GuestLayout>
      <div className="card card-pad">
        <h1 className="t-section">Set your password</h1>
        <p className="mt-1.5 t-muted">Your account was created with a temporary password. Choose your own to continue.</p>
        {error && <p className="mt-4 rounded-lg border border-danger-border bg-danger-bg px-3 py-2 text-sm text-danger">{error}</p>}
        <form className="mt-5 space-y-4" onSubmit={submit}>
          <Field label={<>Current password <span className="required">*</span></>}>
            <input className="field" type="password" name="currentPassword" autoComplete="current-password"
              value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required autoFocus />
          </Field>
          <Field label={<>New password <span className="required">*</span></>} hint="At least 8 characters.">
            <input className="field" type="password" name="newPassword" autoComplete="new-password"
              value={password} onChange={(e) => setPassword(e.target.value)} required />
          </Field>
          <Field label={<>Confirm password <span className="required">*</span></>}>
            <input className="field" type="password" name="confirmPassword" autoComplete="new-password"
              value={confirm} onChange={(e) => setConfirm(e.target.value)} required />
          </Field>
          <button type="submit" className="btn btn-primary w-full">Save Password</button>
        </form>
      </div>
    </GuestLayout>
  );
}
