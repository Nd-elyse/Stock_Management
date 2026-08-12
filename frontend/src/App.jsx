import React from 'react';
import { Routes, Route } from 'react-router-dom';
import './App.css';
import './assets/style.css';

import { AuthProvider } from './context';
import { ToastProvider } from './context';
import { PublicLayout, ProtectedRoute } from './components';

import Home from './pages/Home/Home';
import About from './pages/About/About';
import Contact from './pages/Contact/Contact';
import Login from './pages/Login/Login';
import Admin from './pages/Dashboard/Admin';
import Receptionist from './pages/Dashboard/Receptionist';
import Mechanic from './pages/Dashboard/Mechanic';
import StockManager from './pages/Dashboard/StockManager';

function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <Routes>
          <Route path="/" element={<PublicLayout><Home /></PublicLayout>} />
          <Route path="/about" element={<PublicLayout><About /></PublicLayout>} />
          <Route path="/contact" element={<PublicLayout><Contact /></PublicLayout>} />
          <Route path="/login" element={<Login />} />

          <Route path="/dashboard/admin" element={<ProtectedRoute role="Admin"><Admin /></ProtectedRoute>} />
          <Route path="/dashboard/receptionist" element={<ProtectedRoute role="Receptionist"><Receptionist /></ProtectedRoute>} />
          <Route path="/dashboard/mechanic" element={<ProtectedRoute role="Mechanic"><Mechanic /></ProtectedRoute>} />
          <Route path="/dashboard/stock" element={<ProtectedRoute role="Stock Manager"><StockManager /></ProtectedRoute>} />

          <Route path="*" element={<PublicLayout><Home /></PublicLayout>} />
        </Routes>
      </ToastProvider>
    </AuthProvider>
  );
}

export default App;
