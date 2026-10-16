import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/layouts.jsx';
import { Tabs } from '../../components/ui.jsx';
import { DeliveryHistory } from '../../components/deliveries.jsx';

/**
 * SMS and email delivery rows recorded by the API (sms_deliveries / email_deliveries).
 * Same read-only history as Notifications > SMS History / Email History.
 * This screen does not send messages and cannot clear server records.
 */
export default function Outbox() {
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'email' ? 'email' : 'sms';
  const setTab = (value) => setParams({ tab: value });

  return (
    <>
      <PageHeader title="Outbox"
        subtitle="Delivery history from the server. Messages are created by application events; this page does not send SMS or email." />

      <Tabs active={tab} onSelect={setTab} items={[
        { key: 'sms', label: 'SMS History' },
        { key: 'email', label: 'Email History' },
      ]} />

      <DeliveryHistory key={tab} channel={tab} />
    </>
  );
}
