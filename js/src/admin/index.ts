import app from 'flarum/admin/app';
import SchedulerPage from './components/SchedulerPage';

app.initializers.add('mattoid-plugin-scheduler', () => {
  app.extensionData.for('mattoid-plugin-scheduler').registerPage(SchedulerPage);
});
