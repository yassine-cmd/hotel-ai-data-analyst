import { StrictMode } from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import './shared/styles/index.css';
import { BRAND } from './shared/brand';

const basePath = (import.meta.env.VITE_BASE_PATH || '').replace(/\/$/, '');

document.title = BRAND.name;

fetch(`${basePath}/sanctum/csrf-cookie`, { credentials: 'include' }).catch(() => {});

ReactDOM.createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter basename={basePath || undefined} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
      <App />
    </BrowserRouter>
  </StrictMode>
);
